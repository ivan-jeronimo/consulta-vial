<?php

namespace App\Services\Ingesta;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Convierte en texto lo que un PDF solo tiene como imagen (señales de tránsito
 * con su nombre dibujado, diagramas…). Las páginas que tienen más imágenes que
 * la decoración habitual del documento se renderizan y se envían a un modelo
 * de visión, que transcribe los nombres escritos y describe cada ilustración
 * sin inventar significados.
 */
class DescriptorImagenes
{
    private const NINGUNA = 'NINGUNA';

    private const INSTRUCCIONES = <<<'TXT'
        Esta es la página %d de un documento oficial sobre tránsito y movilidad en México.
        Extrae SOLO la información que está dentro de imágenes o ilustraciones (no repitas el texto corrido de la página).

        - Por cada señal o ilustración con nombre escrito, escribe una línea con este formato exacto:
          ● SEÑAL «NOMBRE TAL COMO ESTÁ ESCRITO»: descripción visual breve (forma, color de fondo y borde, símbolo o número).
        - Si una ilustración no tiene nombre escrito, describe solo lo que se ve, sin suponer su significado.
        - No inventes significados, reglas ni nombres que no estén escritos en la página.
        - Escribe en español. No agregues títulos, introducciones ni comentarios.
        - Si la página no tiene ilustraciones con información útil (solo logotipos, sellos o decoración), responde exactamente: NINGUNA
        TXT;

    public function disponible(): bool
    {
        return (bool) config('rag.ingesta.describir_imagenes')
            && config('rag.llm.provider') === 'gemini'
            && filled(config('rag.embeddings.gemini.key'))
            && $this->modelo() !== null;
    }

    /**
     * Páginas cuyo número de imágenes supera al más frecuente del documento
     * (logotipo, franja y demás decoración que se repite en todas).
     *
     * @param  array<int, int>  $imagenesPorPagina  página => imágenes
     * @return list<int>
     */
    public static function paginasIlustradas(array $imagenesPorPagina, int $maximo = 30): array
    {
        if ($imagenesPorPagina === []) {
            return [];
        }

        $frecuencias = array_count_values($imagenesPorPagina);
        arsort($frecuencias);
        $habitual = (int) array_key_first($frecuencias);

        $paginas = array_keys(array_filter($imagenesPorPagina, fn (int $n) => $n > $habitual));
        sort($paginas);

        return array_slice($paginas, 0, $maximo);
    }

    /**
     * @param  int  $totalPaginas  Para contar también las páginas sin imágenes.
     * @return array<int, string> página => líneas de descripción (solo las que tienen algo útil)
     */
    public function describir(string $rutaPdf, int $totalPaginas): array
    {
        if (! $this->disponible()) {
            return [];
        }

        $paginas = self::paginasIlustradas($this->contarImagenes($rutaPdf, $totalPaginas));
        $huella = hash_file('sha256', $rutaPdf);
        $descripciones = [];

        foreach ($paginas as $pagina) {
            try {
                $texto = Cache::remember(
                    "ilustraciones:{$huella}:{$pagina}:{$this->modelo()}",
                    now()->addDays(30),
                    fn () => $this->describirPagina($rutaPdf, $pagina) ?? self::NINGUNA,
                );
            } catch (\Throwable $e) {
                // Sin descripción se pierde solo lo ilustrado; el resto del documento sigue.
                Log::warning("No se pudo describir la página {$pagina}", ['error' => mb_substr($e->getMessage(), 0, 200)]);

                continue;
            }

            if ($texto !== self::NINGUNA) {
                $descripciones[$pagina] = $texto;
            }
        }

        return $descripciones;
    }

    /** Envía la imagen de una página ya renderizada (PNG) y devuelve sus líneas, o null si no hay nada útil. */
    public function describirImagen(string $png, int $pagina): ?string
    {
        $respuesta = Http::withHeaders(['x-goog-api-key' => config('rag.embeddings.gemini.key')])
            ->timeout(90)
            ->retry(2, 2000, throw: true)
            ->post(config('rag.embeddings.gemini.url')."/models/{$this->modelo()}:generateContent", [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['inline_data' => ['mime_type' => 'image/png', 'data' => base64_encode(file_get_contents($png))]],
                        ['text' => sprintf(self::INSTRUCCIONES, $pagina)],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'maxOutputTokens' => 4096,
                    ...(str_starts_with($this->modelo(), 'gemini-3') ? ['thinkingConfig' => ['thinkingLevel' => 'low']] : []),
                ],
            ]);

        $texto = trim(collect($respuesta->json('candidates.0.content.parts'))
            ->reject(fn (array $p) => $p['thought'] ?? false)
            ->pluck('text')
            ->implode(''));

        $sinNadaUtil = $texto === '' || (mb_strlen($texto) < 20 && str_contains(mb_strtoupper($texto), self::NINGUNA));

        return $sinNadaUtil ? null : $texto;
    }

    private function describirPagina(string $rutaPdf, int $pagina): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'pagina');
        $png = "{$base}.png";

        try {
            Process::run(['pdftoppm', '-f', (string) $pagina, '-l', (string) $pagina, '-r', '110', '-png', '-singlefile', $rutaPdf, $base])->throw();

            return $this->describirImagen($png, $pagina);
        } finally {
            @unlink($base);
            @unlink($png);
        }
    }

    /** @return array<int, int> página => imágenes (incluye páginas con 0) */
    private function contarImagenes(string $rutaPdf, int $totalPaginas): array
    {
        $conteo = array_fill(1, max(1, $totalPaginas), 0);
        $salida = Process::run(['pdfimages', '-list', $rutaPdf])->output();

        foreach (array_slice(explode("\n", $salida), 2) as $linea) {
            $columnas = preg_split('/\s+/', trim($linea));

            // Solo imágenes "reales" (no máscaras de transparencia).
            if (count($columnas) > 2 && ctype_digit($columnas[0]) && $columnas[2] === 'image') {
                $conteo[(int) $columnas[0]] = ($conteo[(int) $columnas[0]] ?? 0) + 1;
            }
        }

        return $conteo;
    }

    private function modelo(): ?string
    {
        return trim(explode(',', (string) config('rag.llm.model'))[0]) ?: null;
    }
}
