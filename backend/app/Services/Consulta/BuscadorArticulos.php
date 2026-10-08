<?php

namespace App\Services\Consulta;

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

        $rankings = [$this->porTexto($pregunta, $estadoId, $municipioId, $candidatos)];

        if ($this->embeddings->esSemantico()) {
            try {
                $rankings[] = $this->porVector($pregunta, $estadoId, $municipioId, $candidatos);
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
        $puntajes = array_slice($puntajes, 0, $limite, preserve_keys: true);

        return ReglamentoArticulo::query()
            ->with('documento.estado', 'documento.municipio')
            ->findMany(array_keys($puntajes))
            ->each(fn (ReglamentoArticulo $a) => $a->setAttribute('relevancia', round($puntajes[$a->id], 5)))
            ->sortByDesc('relevancia')
            ->values();
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
