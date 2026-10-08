<?php

namespace App\Services\Reto;

use App\Models\DocumentoReglamento;
use App\Models\PreguntaReto;
use App\Models\ReglamentoArticulo;
use App\Services\Llm\ClienteLlm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Convierte fragmentos de la guía de examen (o de un reglamento) en preguntas
 * de opción múltiple. Quedan como borrador hasta que alguien las aprueba.
 */
class GeneradorPreguntas
{
    /** Fragmentos por llamada al LLM. */
    public const POR_LOTE = 10;

    private const INSTRUCCIONES = <<<'TXT'
        Eres el autor de un simulador del examen teórico de manejo en Oaxaca, México.
        Recibes fragmentos numerados de una guía de estudio o de un reglamento de tránsito y escribes preguntas de opción múltiple.

        Reglas:
        - Usa ÚNICAMENTE lo que dicen los fragmentos. No inventes cifras, multas, plazos ni reglas que no estén en el texto.
        - Una pregunta por idea concreta y útil para manejar. Omite títulos, índices, listas sin contexto y fragmentos que dependan de una imagen que no ves (por ejemplo "la señal de la figura").
        - 4 opciones: una correcta sin ambigüedad según el texto y 3 incorrectas pero creíbles para alguien que no estudió. Nada de "todas las anteriores" ni "ninguna de las anteriores".
        - Opciones de largo parecido; que la correcta no sea siempre la más larga o la más detallada.
        - Escribe en español de México, con mayúsculas y minúsculas normales (el texto fuente suele venir todo en mayúsculas). Trata al lector de "usted", como en el examen.
        - "explicacion": una o dos frases que digan por qué la correcta es la correcta, basadas en el fragmento.
        - "fragmento": el número del fragmento que respalda la pregunta.
        - Si ningún fragmento sirve, devuelve una lista vacía.

        Responde solo con JSON:
        {"preguntas": [{"fragmento": 1, "pregunta": "...", "opciones": ["...", "...", "...", "..."], "correcta": 0, "explicacion": "..."}]}
        TXT;

    public function __construct(private readonly ClienteLlm $llm) {}

    /** @return Collection<int, ReglamentoArticulo> */
    public function pendientes(DocumentoReglamento $documento, ?string $capitulo = null): Collection
    {
        return ReglamentoArticulo::query()
            ->where('documento_id', $documento->id)
            ->when($capitulo, fn ($q) => $q->where('capitulo', $capitulo))
            ->whereNotIn('id', DB::table('reto_fragmentos_revisados')->select('articulo_id'))
            // Títulos y renglones sueltos no dan para una pregunta.
            ->whereRaw('length(contenido) >= 40')
            ->orderBy('orden')
            ->get();
    }

    /** @return int preguntas creadas */
    public function generar(DocumentoReglamento $documento, ?string $capitulo = null, int $lotes = 3): int
    {
        if (! $this->llm->disponible()) {
            throw new RuntimeException('No hay LLM configurado (LLM_PROVIDER y su API key).');
        }

        $creadas = 0;

        foreach ($this->pendientes($documento, $capitulo)->chunk(self::POR_LOTE)->take($lotes) as $lote) {
            $creadas += $this->procesarLote($documento, $lote->values());
        }

        return $creadas;
    }

    /** @param  Collection<int, ReglamentoArticulo>  $fragmentos */
    private function procesarLote(DocumentoReglamento $documento, Collection $fragmentos): int
    {
        $mensaje = "Documento: {$documento->titulo}\n\n".$fragmentos
            ->map(fn (ReglamentoArticulo $a, int $i) => '['.($i + 1).'] ('.collect([$a->capitulo, $a->seccion, $a->articulo ? "Artículo {$a->articulo}" : null])->filter()->implode(' > ').")\n".$a->contenido)
            ->implode("\n\n");

        ['texto' => $texto, 'modelo' => $modelo] = $this->llm->generar(self::INSTRUCCIONES, $mensaje, json: true);

        $creadas = 0;

        foreach ($this->leerJson($texto) as $p) {
            try {
                $creadas += $this->guardar($documento, $fragmentos, $p, $modelo) ? 1 : 0;
            } catch (Throwable $e) {
                Log::info('Pregunta generada descartada por formato', ['error' => $e->getMessage()]);
            }
        }

        // Revisados aunque no hayan dado preguntas: la siguiente corrida sigue con material nuevo.
        DB::table('reto_fragmentos_revisados')->insertOrIgnore(
            $fragmentos->map(fn (ReglamentoArticulo $a) => ['articulo_id' => $a->id, 'revisado_at' => now()])->all()
        );

        return $creadas;
    }

    private function guardar(DocumentoReglamento $documento, Collection $fragmentos, array $p, string $modelo): bool
    {
        $fragmento = $fragmentos[((int) ($p['fragmento'] ?? 0)) - 1] ?? null;
        $opciones = array_values(array_filter(array_map(fn ($o) => is_string($o) ? trim($o) : '', $p['opciones'] ?? [])));
        $correcta = $p['correcta'] ?? null;

        if (! $fragmento || blank($p['pregunta'] ?? null) || count($opciones) < 3 || count($opciones) > 4
            || count(array_unique(array_map('mb_strtolower', $opciones))) !== count($opciones)
            || ! is_int($correcta) || ! isset($opciones[$correcta])) {
            return false;
        }

        // Los modelos tienden a poner la correcta siempre en el mismo lugar: se barajan.
        $textoCorrecto = $opciones[$correcta];
        shuffle($opciones);

        PreguntaReto::create([
            'documento_id' => $documento->id,
            'fuentes' => [$fragmento->id],
            'categoria' => static::categoria($fragmento->capitulo),
            'pregunta' => trim($p['pregunta']),
            'opciones' => $opciones,
            'correcta' => array_search($textoCorrecto, $opciones, true),
            'explicacion' => filled($p['explicacion'] ?? null) ? trim($p['explicacion']) : null,
            'cita' => $this->cita($documento, $fragmento),
            'modelo' => $modelo,
        ]);

        return true;
    }

    /** @return list<array> */
    private function leerJson(string $texto): array
    {
        // Algunos modelos envuelven el JSON en ```json … ```.
        $texto = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/', '', $texto);
        $datos = json_decode($texto, true);

        if (! is_array($datos) || ! is_array($datos['preguntas'] ?? null)) {
            throw new RuntimeException('El LLM no devolvió el JSON esperado: '.Str::limit($texto, 200));
        }

        return array_values(array_filter($datos['preguntas'], 'is_array'));
    }

    /** "I.-CONOCIMIENTOS GENERALES" → "Conocimientos generales"; "IV.- PARA OBTENCIÓN DE LICENCIA TIPO B" → "Obtención de licencia tipo B". */
    public static function categoria(?string $capitulo): string
    {
        $texto = trim(preg_replace('/^[IVXLC]+\s*\.?\s*-?\s*/u', '', (string) $capitulo));
        $texto = preg_replace('/^para\s+/iu', '', $texto);
        $texto = Str::ucfirst(mb_strtolower($texto));
        $texto = preg_replace_callback('/\btipo\s+"?([a-z])"?/u', fn ($m) => 'tipo '.mb_strtoupper($m[1]), $texto);

        return $texto !== '' ? Str::limit($texto, 150, '') : 'General';
    }

    private function cita(DocumentoReglamento $documento, ReglamentoArticulo $fragmento): string
    {
        $ubicacion = $fragmento->articulo
            ? "Art. {$fragmento->articulo}".($fragmento->fraccion ? ", fracc. {$fragmento->fraccion}" : '')
            : static::categoria($fragmento->capitulo);

        return Str::limit("{$documento->titulo} · {$ubicacion}", 300, '');
    }
}
