<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Llamada de texto al LLM configurado (LLM_PROVIDER) con modelos de respaldo.
 * La usan las respuestas del bot.
 */
class ClienteLlm
{
    /** Segundos mínimos para que valga la pena empezar otro intento. */
    private const MINIMO_POR_INTENTO = 5;

    /** Segundos que se espera la respuesta del intento en curso (lo fija generar()). */
    private int $espera = 25;

    public function disponible(): bool
    {
        return $this->proveedor() !== 'none' && filled($this->apiKey());
    }

    /**
     * LLM_MODEL admite una lista separada por comas: el primero es el preferido
     * y los siguientes se usan si ese está saturado (503) o sin cuota (429).
     *
     * @return list<string>
     */
    public function modelos(): array
    {
        $modelos = array_values(array_filter(array_map('trim', explode(',', (string) config('rag.llm.model')))));

        if ($modelos === [] && $this->proveedor() === 'anthropic') {
            $modelos = [config('rag.llm.anthropic.model')];
        }

        return $modelos ?: throw new RuntimeException("Define LLM_MODEL para el proveedor {$this->proveedor()}.");
    }

    /**
     * @param  bool  $json  pide al modelo un objeto JSON (modo JSON en Gemini y OpenAI)
     * @param  string|null  $razonamiento  nivel de "thinking" de Gemini 3; null = LLM_THINKING
     * @return array{texto: string, modelo: string}
     */
    public function generar(string $instrucciones, string $mensaje, bool $json = false, ?string $razonamiento = null): array
    {
        $modelos = $this->modelos();
        $error = new RuntimeException('Sin modelos configurados.');
        $inicio = microtime(true);
        $presupuesto = (int) config('rag.llm.presupuesto_segundos');
        $restante = fn () => $presupuesto - (microtime(true) - $inicio);

        // Las fallas de Gemini (saturado, respuesta malformada) suelen durar segundos:
        // si todos los modelos fallan así, se espera un poco y se da una segunda vuelta
        // antes de rendirse (entonces el chat muestra solo los artículos). Todo dentro
        // del presupuesto: el servidor web corta a los 150 s y la persona se desespera antes.
        foreach ([1, 2] as $vuelta) {
            if ($vuelta === 2) {
                $espera = (int) config('rag.llm.espera_segunda_vuelta');
                if ($restante() < $espera + self::MINIMO_POR_INTENTO) {
                    break;
                }
                Sleep::for($espera)->seconds();
            }

            foreach ($modelos as $modelo) {
                if ($restante() < self::MINIMO_POR_INTENTO) {
                    break 2;
                }
                // Si Gemini no contesta en este tiempo casi nunca lo hace: mejor pasar al siguiente.
                $this->espera = (int) min(config('rag.llm.timeout_intento'), $restante());

                try {
                    $texto = match ($this->proveedor()) {
                        'anthropic' => $this->anthropic($instrucciones, $mensaje, $modelo),
                        'openai' => $this->openai($instrucciones, $mensaje, $modelo, $json),
                        'gemini' => $this->gemini($instrucciones, $mensaje, $modelo, $json, $razonamiento ?? config('rag.llm.razonamiento')),
                        default => throw new RuntimeException('LLM no configurado.'),
                    };

                    return ['texto' => $texto, 'modelo' => $modelo];
                } catch (\Throwable $e) {
                    if (! $this->esReintentable($e)) {
                        throw $e;
                    }

                    $error = $e;
                    Log::warning("LLM {$modelo} no disponible (vuelta {$vuelta}), se prueba el siguiente", ['error' => mb_substr($e->getMessage(), 0, 300)]);
                }
            }
        }

        throw $error;
    }

    private function anthropic(string $instrucciones, string $mensaje, string $modelo): string
    {
        $respuesta = Http::withHeaders([
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout($this->espera)
            // Un reintento rápido solo si respondió saturado; si se quedó colgado, se pasa al siguiente modelo.
            ->retry(2, 1000, fn ($e) => $e instanceof RequestException && $this->esReintentable($e), throw: true)
            ->post(config('rag.llm.anthropic.url').'/messages', [
                'model' => $modelo,
                'max_tokens' => config('rag.llm.max_tokens'),
                'system' => $instrucciones,
                'messages' => [['role' => 'user', 'content' => $mensaje]],
            ]);

        return collect($respuesta->json('content'))->where('type', 'text')->pluck('text')->implode('');
    }

    private function openai(string $instrucciones, string $mensaje, string $modelo, bool $json): string
    {
        $respuesta = Http::withToken($this->apiKey())
            ->timeout($this->espera)
            // Un reintento rápido solo si respondió saturado; si se quedó colgado, se pasa al siguiente modelo.
            ->retry(2, 1000, fn ($e) => $e instanceof RequestException && $this->esReintentable($e), throw: true)
            ->post(config('rag.embeddings.openai.url').'/chat/completions', [
                'model' => $modelo,
                'max_completion_tokens' => config('rag.llm.max_tokens'),
                'messages' => [
                    ['role' => 'system', 'content' => $instrucciones],
                    ['role' => 'user', 'content' => $mensaje],
                ],
                ...($json ? ['response_format' => ['type' => 'json_object']] : []),
            ]);

        return (string) $respuesta->json('choices.0.message.content');
    }

    private function gemini(string $instrucciones, string $mensaje, string $modelo, bool $json, ?string $razonamiento): string
    {
        $respuesta = Http::withHeaders(['x-goog-api-key' => $this->apiKey()])
            ->timeout($this->espera)
            // Un reintento rápido solo si respondió saturado; si se quedó colgado, se pasa al siguiente modelo.
            ->retry(2, 1000, fn ($e) => $e instanceof RequestException && $this->esReintentable($e), throw: true)
            ->post(config('rag.embeddings.gemini.url')."/models/{$modelo}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $instrucciones]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $mensaje]]]],
                'generationConfig' => [
                    'maxOutputTokens' => config('rag.llm.max_tokens'),
                    ...($json ? ['responseMimeType' => 'application/json'] : []),
                    // Explicar artículos ya seleccionados no requiere razonamiento largo:
                    // con "low" la respuesta tarda ~2.5 s en vez de ~6-16 s.
                    ...(str_starts_with($modelo, 'gemini-3') && $razonamiento
                        ? ['thinkingConfig' => ['thinkingLevel' => $razonamiento]]
                        : []),
                ],
            ]);

        // Los modelos con "thinking" no devuelven sus pensamientos como texto; se omiten.
        $texto = collect($respuesta->json('candidates.0.content.parts'))
            ->reject(fn (array $parte) => $parte['thought'] ?? false)
            ->pluck('text')
            ->implode('');

        if ($texto === '') {
            $motivo = (string) $respuesta->json('candidates.0.finishReason');

            // MAX_TOKENS: el razonamiento interno agotó maxOutputTokens; reintentar no sirve.
            // Otros (MALFORMED_RESPONSE…) son fallas pasajeras de Gemini: se reintenta.
            throw $motivo === 'MAX_TOKENS'
                ? new RuntimeException('Gemini devolvió una respuesta vacía (MAX_TOKENS). Sube LLM_MAX_TOKENS.')
                : new RespuestaVaciaLlm("Gemini devolvió una respuesta vacía ({$motivo}).");
        }

        return $texto;
    }

    private function proveedor(): string
    {
        return (string) config('rag.llm.provider');
    }

    private function apiKey(): ?string
    {
        return match ($this->proveedor()) {
            'anthropic' => config('rag.llm.anthropic.key'),
            'openai' => config('rag.embeddings.openai.key'),
            'gemini' => config('rag.embeddings.gemini.key'),
            default => null,
        };
    }

    /** Fallas pasajeras: saturación, cuota, red o una respuesta vacía que no fue por MAX_TOKENS. */
    private function esReintentable(\Throwable $e): bool
    {
        return match (true) {
            $e instanceof RequestException => in_array($e->response->status(), [429, 500, 502, 503, 504, 529], true),
            $e instanceof ConnectionException, $e instanceof RespuestaVaciaLlm => true,
            default => false,
        };
    }
}
