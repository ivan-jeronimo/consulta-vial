<?php

namespace App\Jobs;

use App\Enums\EstatusProcesamiento;
use App\Enums\EstatusVigencia;
use App\Models\DocumentoReglamento;
use App\Models\ReglamentoArticulo;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Ingesta\ChunkArticulo;
use App\Services\Ingesta\ExtractorTexto;
use App\Services\Ingesta\NormalizadorTexto;
use App\Services\Ingesta\SegmentadorArticulos;
use App\Services\Ingesta\SegmentadorSecciones;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Pipeline de ingesta de un reglamento:
 * archivo → texto → normalización → chunks por artículo → embeddings → pgvector.
 *
 * Los artículos anteriores (si es un re-proceso) se reemplazan dentro de una
 * sola transacción: el bot sigue viendo la versión previa hasta el COMMIT.
 */
class ProcesarReglamentoJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    // Holgado para el nivel gratuito de embeddings (~100 fragmentos por minuto).
    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    // Evita dos ingestas simultáneas del mismo documento; caduca por si un worker muere.
    public int $uniqueFor = 4 * 3600;

    public function __construct(public DocumentoReglamento $documento)
    {
        $this->onQueue(config('rag.ingesta.cola'));
    }

    public function uniqueId(): string
    {
        return (string) $this->documento->id;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(
        ExtractorTexto $extractor,
        NormalizadorTexto $normalizador,
        SegmentadorArticulos $porArticulos,
        SegmentadorSecciones $porSecciones,
        EmbeddingService $embeddings,
    ): void {
        $documento = $this->documento;

        $documento->update([
            'estatus_procesamiento' => EstatusProcesamiento::Procesando,
            'error_mensaje' => null,
        ]);

        // 1. Extracción + normalización
        $ruta = Storage::disk(config('rag.ingesta.disk'))->path($documento->archivo_path);
        $texto = $normalizador->normalizar($extractor->extraer($ruta, $documento->archivo_mime));

        // 2. Chunking semántico. Normas: Título > Capítulo > Artículo > Fracción > Inciso.
        //    Manuales y guías: Tema > Subtema > párrafos.
        $chunks = $documento->tipo->seDividePorArticulos()
            ? $porArticulos->segmentar($texto)
            : $porSecciones->segmentar($texto);
        unset($texto);

        if ($chunks === []) {
            throw new RuntimeException('No se obtuvo ningún fragmento del documento.');
        }

        Log::info('Reglamento segmentado', ['documento_id' => $documento->id, 'chunks' => count($chunks)]);

        // 3. Embeddings por lotes (fuera de la transacción: son llamadas HTTP lentas)
        $vectores = $embeddings->embedEnLotes(
            array_map(fn (ChunkArticulo $c) => $c->textoParaEmbedding($documento->titulo), $chunks),
            fn (int $hechos, int $total) => Log::debug("Embeddings {$hechos}/{$total}", ['documento_id' => $documento->id]),
        );

        // 4. Reemplazo atómico de los artículos del documento
        DB::transaction(function () use ($documento, $chunks, $vectores, $embeddings) {
            $documento->articulos()->delete();

            $ahora = now();

            foreach (array_chunk($chunks, 200, preserve_keys: true) as $lote) {
                $filas = [];

                foreach ($lote as $i => $chunk) {
                    $filas[] = [
                        'documento_id' => $documento->id,
                        'nivel' => $documento->nivel->value,
                        'estado_id' => $documento->estado_id,
                        'municipio_id' => $documento->municipio_id,
                        'titulo' => $chunk->titulo,
                        'capitulo' => $chunk->capitulo,
                        'seccion' => $chunk->seccion,
                        'articulo' => $chunk->articulo,
                        'fraccion' => $chunk->fraccion,
                        'inciso' => $chunk->inciso,
                        'orden' => $chunk->orden,
                        'contenido' => $chunk->contenido,
                        'contexto' => $chunk->contexto,
                        'tokens_aprox' => $chunk->tokensAproximados(),
                        'embedding' => json_encode($vectores[$i]), // pgvector acepta '[0.1,0.2,...]'
                        'metadata' => json_encode([
                            'documento' => $documento->titulo,
                            'anio' => $documento->anio,
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ];
                }

                ReglamentoArticulo::insert($filas);
            }

            $documento->update([
                'estatus_procesamiento' => EstatusProcesamiento::Completado,
                'chunks_count' => count($chunks),
                'embedding_modelo' => $embeddings->modelo(),
                'procesado_at' => $ahora,
            ]);

            // Reforma: el documento reemplazado deja de ser consultable en el mismo COMMIT.
            $documento->reemplazaA?->update(['estatus_vigencia' => EstatusVigencia::Derogado]);
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->documento->update([
            'estatus_procesamiento' => EstatusProcesamiento::Error,
            'error_mensaje' => mb_substr($e?->getMessage() ?? 'Error desconocido', 0, 2000),
        ]);
    }
}
