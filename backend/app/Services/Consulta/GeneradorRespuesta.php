<?php

namespace App\Services\Consulta;

use App\Models\ReglamentoArticulo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Redacta la respuesta al ciudadano a partir de los artículos recuperados.
 * El modelo solo puede usar ese contexto y debe citarlo.
 */
class GeneradorRespuesta
{
    private const INSTRUCCIONES = <<<'TXT'
        Eres un asistente que orienta a conductores, ciclistas y peatones sobre los reglamentos de tránsito y movilidad en México.

        Reglas:
        - Responde ÚNICAMENTE con base en los artículos proporcionados. No uses conocimiento externo sobre otras leyes.
        - Empieza con una respuesta directa (sí / no / depende) en una o dos frases, y después explica.
        - Cita cada afirmación con el artículo y el reglamento, por ejemplo: (Art. 54, Reglamento de Movilidad de Oaxaca de Juárez).
        - Si el texto no menciona exactamente el caso preguntado, dilo con claridad y separa lo que el reglamento dice literalmente de lo que se interpreta a partir de él.
        - Si los artículos no permiten responder, dilo y no inventes.
        - Si hay normas de distinto nivel (federal, estatal, municipal), indica cuál aplica; en su municipio prevalece el reglamento municipal en lo que regula.
        - Cada fuente indica su tipo. Los reglamentos y leyes son obligatorios. Los manuales y guías (por ejemplo, el manual para el examen de manejo) son material de estudio: úsalos para explicar o dar ejemplos y cítalos por su sección (por ejemplo: Manual del Conductor de Oaxaca 2023, sección «Señales restrictivas»), pero si contradicen a un reglamento o ley, prevalece la norma y debes decirlo.
        - Lenguaje claro y breve, sin tecnicismos innecesarios. Escribe en español de México.
        - Termina con una línea indicando que es información orientativa y no asesoría legal.
        - Después de todo, agrega estas dos líneas finales, cada una aparte:
          CUBIERTO: sí  (si los artículos permiten responder la pregunta, aunque sea por interpretación) o CUBIERTO: no  (si no tratan el tema preguntado)
          FUENTES: seguido de los números entre corchetes de los artículos que usaste para responder, separados por comas (por ejemplo: FUENTES: 1, 4). Incluye solo los que respaldan tu respuesta. Si no usaste ninguno, escribe: FUENTES: ninguna
        TXT;

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

    /** @param  Collection<int, ReglamentoArticulo>  $articulos */
    public function responder(string $pregunta, string $ubicacion, Collection $articulos): RespuestaGenerada
    {
        $mensaje = "Ubicación del usuario: {$ubicacion}\n\n"
            ."Artículos aplicables encontrados:\n\n"
            .$articulos->values()->map(fn (ReglamentoArticulo $a, int $i) => $this->formatearArticulo($a, $i + 1))->implode("\n\n")
            ."\n\nPregunta: {$pregunta}";

        $modelos = $this->modelos();

        foreach ($modelos as $i => $modelo) {
            try {
                $texto = match ($this->proveedor()) {
                    'anthropic' => $this->anthropic($mensaje, $modelo),
                    'openai' => $this->openai($mensaje, $modelo),
                    'gemini' => $this->gemini($mensaje, $modelo),
                    default => throw new RuntimeException('LLM no configurado.'),
                };

                return RespuestaGenerada::desdeTexto($texto, $articulos->count(), $modelo);
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

    private function formatearArticulo(ReglamentoArticulo $a, int $n): string
    {
        $doc = $a->documento;
        $ambito = collect([$doc->nivel->getLabel(), $doc->municipio?->nombre, $doc->estado?->nombre])->filter()->implode(', ');
        $tipo = $doc->tipo->esNormativo() ? $doc->tipo->getLabel() : "{$doc->tipo->getLabel()}, material de estudio, no norma";

        // Normas: ruta por artículo. Manuales: por tema y sección.
        $ruta = $a->articulo
            ? collect([
                "Artículo {$a->articulo}",
                $a->fraccion ? "fracción {$a->fraccion}" : null,
                $a->inciso ? "inciso {$a->inciso})" : null,
            ])->filter()->implode(', ')
            : (collect([$a->capitulo, $a->seccion])->filter()->map(fn ($s) => "«{$s}»")->implode(' > ') ?: 'Fragmento');

        // Para un apartado se antepone el encabezado del artículo, marcado como tal
        // para que el modelo no lo confunda con el contenido aplicable.
        $texto = $a->contexto
            ? "(Encabezado del artículo: {$a->contexto})\n{$a->contenido}"
            : $a->contenido;

        return "[{$n}] {$doc->titulo} [{$tipo}] ({$ambito}, {$doc->anio}) — {$ruta}\n{$texto}";
    }

    private function anthropic(string $mensaje, string $modelo): string
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
                'system' => self::INSTRUCCIONES,
                'messages' => [['role' => 'user', 'content' => $mensaje]],
            ]);

        return collect($respuesta->json('content'))->where('type', 'text')->pluck('text')->implode('');
    }

    private function openai(string $mensaje, string $modelo): string
    {
        $respuesta = Http::withToken($this->apiKey())
            ->timeout(90)
            ->retry(2, 1500, fn ($e) => $this->esReintentable($e), throw: true)
            ->post(config('rag.embeddings.openai.url').'/chat/completions', [
                'model' => $modelo,
                'max_completion_tokens' => config('rag.llm.max_tokens'),
                'messages' => [
                    ['role' => 'system', 'content' => self::INSTRUCCIONES],
                    ['role' => 'user', 'content' => $mensaje],
                ],
            ]);

        return (string) $respuesta->json('choices.0.message.content');
    }

    private function gemini(string $mensaje, string $modelo): string
    {
        $respuesta = Http::withHeaders(['x-goog-api-key' => $this->apiKey()])
            ->timeout(90)
            ->retry(2, 1500, fn ($e) => $this->esReintentable($e), throw: true)
            ->post(config('rag.embeddings.gemini.url')."/models/{$modelo}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => self::INSTRUCCIONES]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $mensaje]]]],
                'generationConfig' => [
                    'maxOutputTokens' => config('rag.llm.max_tokens'),
                    // Explicar artículos ya seleccionados no requiere razonamiento largo:
                    // con "low" la respuesta tarda ~2.5 s en vez de ~6-16 s.
                    ...(str_starts_with($modelo, 'gemini-3') && config('rag.llm.razonamiento')
                        ? ['thinkingConfig' => ['thinkingLevel' => config('rag.llm.razonamiento')]]
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
