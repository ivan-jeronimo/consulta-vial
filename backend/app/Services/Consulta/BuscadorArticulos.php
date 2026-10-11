<?php

namespace App\Services\Consulta;

use App\Enums\NivelJurisdiccion;
use App\Enums\TipoDocumento;
use App\Models\ReglamentoArticulo;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Búsqueda híbrida sobre la normativa aplicable a un territorio:
 *  - vectorial (similitud semántica, pgvector HNSW coseno)
 *  - léxica (full-text en español sin acentos, OR entre términos)
 * fusionadas con Reciprocal Rank Fusion, que no requiere calibrar escalas.
 */
class BuscadorArticulos
{
    private const RRF_K = 60;

    // Pregunta → tsquery con OR entre los lexemas: con AND, palabras de relleno
    // ("permite", "cuando") dejarían fuera artículos relevantes.
    private const TSQUERY = "to_tsquery('spanish', replace(plainto_tsquery('spanish', immutable_unaccent(?))::text, '&', '|'))";

    public function __construct(private EmbeddingService $embeddings) {}

    /**
     * @return Collection<int, ReglamentoArticulo> con atributo `relevancia`
     */
    public function buscar(string $pregunta, ?int $estadoId, ?int $municipioId, ?int $limite = null): Collection
    {
        $limite ??= config('rag.busqueda.resultados');
        $candidatos = config('rag.busqueda.candidatos');
        // Con las palabras del reglamento ("aprendiendo a manejar" → "enseñanza, escuela de manejo").
        $consulta = Sinonimos::expandir($pregunta);

        $rankings = [$this->porTexto($consulta, $estadoId, $municipioId, $candidatos)];

        if ($this->embeddings->esSemantico()) {
            try {
                $rankings[] = $this->porVector($consulta, $estadoId, $municipioId, $candidatos);
            } catch (\Throwable $e) {
                // Sin embedding (cuota agotada, red, etc.) la búsqueda léxica sigue sirviendo.
                Log::warning('Búsqueda vectorial no disponible; se usa solo texto completo', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        $puntajes = [];
        foreach ($rankings as $ids) {
            foreach ($ids as $posicion => $id) {
                $puntajes[$id] = ($puntajes[$id] ?? 0) + 1 / (self::RRF_K + $posicion + 1);
            }
        }

        arsort($puntajes);

        if ($puntajes === []) {
            return new Collection;
        }

        $datos = $this->datos(array_keys($puntajes));
        $puntajes = $this->repartir($puntajes, $datos, $limite, $municipioId);
        $puntajes = $this->conVecinos($puntajes, $datos);

        return ReglamentoArticulo::query()
            ->with('documento.estado', 'documento.municipio')
            ->findMany(array_keys($puntajes))
            ->each(fn (ReglamentoArticulo $a) => $a->setAttribute('relevancia', round($puntajes[$a->id], 5)))
            ->sortByDesc('relevancia')
            ->values();
    }

    /**
     * Nivel, documento, posición y si es material de estudio (manual o guía) de cada candidato.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object{nivel: string, documento_id: int, orden: int, estudio: bool}>
     */
    private function datos(array $ids): Collection
    {
        return ReglamentoArticulo::query()
            ->join('documentos_reglamentos as d', 'd.id', '=', 'reglamentos_articulos.documento_id')
            ->whereIn('reglamentos_articulos.id', $ids)
            ->toBase()
            ->get(['reglamentos_articulos.id', 'reglamentos_articulos.nivel', 'reglamentos_articulos.documento_id', 'reglamentos_articulos.orden', 'd.tipo'])
            ->map(function (object $f) {
                $f->estudio = $f->tipo === TipoDocumento::Manual->value;

                return $f;
            })
            ->keyBy('id');
    }

    /**
     * Qué candidatos llegan al modelo:
     *  - Con un municipio elegido se consultan dos reglamentos: el estatal (aplica en
     *    todo el estado) y el municipal. Cada uno se queda con sus mejores artículos
     *    (hasta `minimo_por_nivel`), para que el más extenso no deje fuera al otro.
     *  - La guía de examen ocupa a lo más `maximo_material_estudio` lugares: sus
     *    cientos de frases cortas ("al conducir…") acaparaban la búsqueda y la
     *    respuesta se quedaba sin un solo artículo del reglamento.
     * El resto de los lugares se llena por relevancia.
     *
     * @param  array<int, float>  $puntajes  ordenados de mayor a menor
     * @return array<int, float>
     */
    private function repartir(array $puntajes, Collection $datos, int $limite, ?int $municipioId): array
    {
        $esNorma = fn (int $id) => ! $datos[$id]->estudio;
        $elegidos = [];

        if ($municipioId) {
            $minimo = (int) config('rag.busqueda.minimo_por_nivel');

            foreach ([NivelJurisdiccion::Estatal, NivelJurisdiccion::Municipal] as $nivel) {
                $delNivel = array_filter($puntajes, fn (int $id) => $esNorma($id) && $datos[$id]->nivel === $nivel->value, ARRAY_FILTER_USE_KEY);
                $elegidos += array_slice($delNivel, 0, $minimo, preserve_keys: true);
            }
        }

        $maximoEstudio = (int) config('rag.busqueda.maximo_material_estudio');
        $estudio = 0;

        foreach ($puntajes as $id => $puntaje) {
            if (count($elegidos) >= $limite) {
                break;
            }
            if (isset($elegidos[$id]) || (! $esNorma($id) && $estudio >= $maximoEstudio)) {
                continue;
            }
            $elegidos[$id] = $puntaje;
            $estudio += $esNorma($id) ? 0 : 1;
        }

        arsort($elegidos);

        return $elegidos;
    }

    /**
     * Bloques temáticos: cuando salen artículos seguidos de un mismo reglamento
     * (p. ej. 257 y 258, de las autoescuelas), los de junto suelen tratar lo mismo
     * aunque no compartan palabras con la pregunta (el 256: "durante la enseñanza
     * los conductores…"). Se agregan el anterior y el siguiente de cada bloque,
     * hasta `vecinos` en total, con un puntaje un poco menor.
     *
     * @param  array<int, float>  $elegidos
     * @return array<int, float>
     */
    private function conVecinos(array $elegidos, Collection $datos): array
    {
        $maximo = (int) config('rag.busqueda.vecinos');
        $normas = collect(array_keys($elegidos))
            ->map(fn (int $id) => $datos[$id])
            ->reject(fn (object $f) => $f->estudio);
        $agregados = 0;

        foreach ($normas->groupBy('documento_id') as $documentoId => $delDocumento) {
            $ordenados = $delDocumento->sortBy('orden')->values();

            for ($i = 1; $i < $ordenados->count() && $agregados < $maximo; $i++) {
                [$a, $b] = [$ordenados[$i - 1], $ordenados[$i]];

                if ($b->orden - $a->orden > 2) {
                    continue;
                }

                $vecinos = ReglamentoArticulo::query()
                    ->where('documento_id', $documentoId)
                    ->whereBetween('orden', [$a->orden - 1, $b->orden + 1])
                    ->whereKeyNot(array_keys($elegidos))
                    ->orderBy('orden')
                    ->limit($maximo - $agregados)
                    ->pluck('id');

                $puntaje = min($elegidos[$a->id], $elegidos[$b->id]) * 0.9;
                foreach ($vecinos as $id) {
                    $elegidos[$id] = $puntaje;
                    $agregados++;
                }
            }
        }

        arsort($elegidos);

        return $elegidos;
    }

    /**
     * Ordena por cobertura (cuántos términos distintos de la pregunta contiene el
     * artículo) y desempata con ts_rank_cd. Así un artículo que solo comparte
     * "permite" no compite con uno que contiene "vuelta", "derecha" y "continua".
     *
     * @return list<int>
     */
    private function porTexto(string $pregunta, ?int $estadoId, ?int $municipioId, int $limite): array
    {
        $lexemas = DB::selectOne(
            "SELECT tsvector_to_array(to_tsvector('spanish', immutable_unaccent(?)))::text[] AS l",
            [$pregunta],
        )->l;
        $lexemas = $lexemas === '{}' ? [] : str_getcsv(trim($lexemas, '{}'));

        if ($lexemas === []) {
            return [];
        }

        $cobertura = implode(' + ', array_fill(0, count($lexemas), '(contenido_tsv @@ ?::tsquery)::int'));
        $terminos = array_map(fn (string $l) => "'".str_replace("'", "''", $l)."'", $lexemas);

        return ReglamentoArticulo::aplicableA($estadoId, $municipioId)
            ->whereRaw('contenido_tsv @@ '.self::TSQUERY, [$pregunta])
            ->whereRaw("({$cobertura}) >= ?", [...$terminos, min(2, count($lexemas))])
            ->orderByRaw("({$cobertura}) DESC", $terminos)
            // 1|4: normaliza por longitud y por distancia media entre términos.
            ->orderByRaw('ts_rank_cd(contenido_tsv, '.self::TSQUERY.', 1|4) DESC', [$pregunta])
            ->limit($limite)
            ->pluck('id')
            ->all();
    }

    /** @return list<int> */
    private function porVector(string $pregunta, ?int $estadoId, ?int $municipioId, int $limite): array
    {
        $vector = $this->embeddings->embedConsulta($pregunta);

        return DB::transaction(function () use ($vector, $estadoId, $municipioId, $limite) {
            // Con filtros WHERE, HNSW puede devolver menos de $limite filas;
            // el escaneo iterativo (pgvector ≥ 0.8) sigue buscando hasta llenarlas.
            DB::statement('SET LOCAL hnsw.iterative_scan = relaxed_order');

            return ReglamentoArticulo::aplicableA($estadoId, $municipioId)
                ->orderByVectorDistance('embedding', $vector)
                ->limit($limite)
                ->pluck('id')
                ->all();
        });
    }
}
