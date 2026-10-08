<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Llamada de texto al LLM configurado (LLM_PROVIDER) con modelos de respaldo.
 * La usan las respuestas del bot y la generación de preguntas del reto.
 */
class ClienteLlm
{
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

        foreach ($modelos as $i => $modelo) {
            try {
                $texto = match ($this->proveedor()) {
                    'anthropic' => $this->anthropic($instrucciones, $mensaje, $modelo),
                    'openai' => $this->openai($instrucciones, $mensaje, $modelo, $json),
                    'gemini' => $this->gemini($instrucciones, $mensaje, $modelo, $json, $razonamiento ?? config('rag.llm.razonamiento')),
                    default => throw new RuntimeException('LLM no configurado.'),
                };

                return ['texto' => $texto, 'modelo' => $modelo];
            } catch (\Throwable $e) {
                $ultimo = $i === array_key_last($modelos);

                if ($ultimo || ! $this->esReintentable($e)) {
                    throw $e;
                }

                Log::warning("LLM {$modelo} no disponible, se usa el respaldo", ['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }

        throw new RuntimeException('Sin modelos configurados.');
    }

    private function anthropic(string $instrucciones, string $mensaje, string $modelo): string
    {
        $respuesta = Http::withHeaders([
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(90)
            ->retry(2, 1500, fn ($e) => $this->esReintentable($e), throw: true)
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
            ->timeout(90)
            ->retry(2, 1500, fn ($e) => $this->esReintentable($e), throw: true)
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
            ->timeout(120)
            ->retry(2, 1500, fn ($e) => $this->esReintentable($e), throw: true)
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
            // Típico: el razonamiento interno agotó maxOutputTokens (finishReason MAX_TOKENS).
            throw new RuntimeException('Gemini devolvió una respuesta vacía ('.$respuesta->json('candidates.0.finishReason').'). Sube LLM_MAX_TOKENS.');
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

    private function esReintentable(\Throwable $e): bool
    {
        return ! $e instanceof \Illuminate\Http\Client\RequestException
            || in_array($e->response->status(), [429, 500, 502, 503, 504, 529], true);
    }
}
