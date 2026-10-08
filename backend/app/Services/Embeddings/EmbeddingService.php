<?php

namespace App\Services\Embeddings;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Genera embeddings de documentos por lotes:
 *  - reutiliza los vectores ya calculados (caché por modelo + texto), de modo
 *    que un Job reintentado retoma donde iba y re-procesar un documento sin
 *    cambios no consume cuota;
 *  - respeta un límite de textos por minuto (el nivel gratuito de Gemini
 *    admite 100) esperando antes de cada lote en vez de chocar con un 429;
 *  - valida que cada vector tenga la dimensión de la columna vector(N).
 */
class EmbeddingService
{
    private const TTL_CACHE = 60 * 60 * 24 * 30;

    /** @var list<array{0: float, 1: int}> envíos del último minuto: [instante, textos] */
    private array $envios = [];

    public function __construct(
        private EmbeddingProvider $provider,
        private int $batchSize,
        private int $dimensions,
        private Cache $cache,
        private int $textosPorMinuto = 0,
    ) {}

    /**
     * @param  list<string>  $textos
     * @param  callable(int $procesados, int $total): void|null  $progreso
     * @return list<list<float>>
     */
    public function embedEnLotes(array $textos, ?callable $progreso = null): array
    {
        $vectores = [];
        $pendientes = [];

        foreach ($textos as $i => $texto) {
            $guardado = $this->esSemantico() ? $this->cache->get($this->clave($texto)) : null;

            if ($guardado !== null) {
                $vectores[$i] = array_values(unpack('g*', $guardado));
            } else {
                $pendientes[$i] = $texto;
            }
        }

        if ($progreso && $vectores) {
            $progreso(count($vectores), count($textos));
        }

        $tamanoLote = $this->textosPorMinuto > 0 ? min($this->batchSize, $this->textosPorMinuto) : $this->batchSize;

        foreach (array_chunk($pendientes, $tamanoLote, preserve_keys: true) as $lote) {
            $this->esperarCuota(count($lote));
            $resultado = $this->provider->embed(array_values($lote));
            $this->envios[] = [$this->ahora(), count($lote)];

            if (count($resultado) !== count($lote)) {
                throw new RuntimeException('El proveedor devolvió '.count($resultado).' vectores para '.count($lote).' textos.');
            }

            foreach (array_keys($lote) as $j => $i) {
                $vector = $resultado[$j];

                if (count($vector) !== $this->dimensions) {
                    throw new RuntimeException('Dimensión '.count($vector)." ≠ {$this->dimensions} configurada en EMBEDDINGS_DIMENSIONS.");
                }

                $vectores[$i] = $vector;

                if ($this->esSemantico()) {
                    $this->cache->put($this->clave($lote[$i]), pack('g*', ...$vector), self::TTL_CACHE);
                }
            }

            if ($progreso) {
                $progreso(count($vectores), count($textos));
            }
        }

        ksort($vectores);

        return array_values($vectores);
    }

    /**
     * Con caché: la búsqueda y el registro de métricas usan el mismo vector, y
     * las preguntas repetidas no vuelven a pagar el embedding.
     *
     * @return list<float>
     */
    public function embedConsulta(string $pregunta): array
    {
        if (! $this->esSemantico()) {
            return $this->provider->embed([$pregunta], consulta: true)[0];
        }

        $clave = 'embedding-consulta:'.sha1("{$this->provider->modelo()}|{$this->dimensions}|".mb_strtolower(trim($pregunta)));
        $guardado = $this->cache->get($clave);

        if ($guardado !== null) {
            return array_values(unpack('g*', $guardado));
        }

        $vector = $this->provider->embed([$pregunta], consulta: true)[0];
        $this->cache->put($clave, pack('g*', ...$vector), 60 * 60 * 24 * 7);

        return $vector;
    }

    /** Con el proveedor fake los vectores son ruido: la búsqueda vectorial se omite. */
    public function esSemantico(): bool
    {
        return ! $this->provider instanceof FakeEmbeddings;
    }

    public function modelo(): string
    {
        return $this->provider->modelo();
    }

    /** Duerme lo necesario para que el próximo lote no exceda el límite por minuto. */
    private function esperarCuota(int $textos): void
    {
        if ($this->textosPorMinuto <= 0) {
            return;
        }

        while (true) {
            $ahora = $this->ahora();
            $this->envios = array_values(array_filter($this->envios, fn ($e) => $e[0] > $ahora - 60));
            $enVentana = array_sum(array_column($this->envios, 1));

            if ($enVentana + $textos <= $this->textosPorMinuto) {
                return;
            }

            // Espera a que salga de la ventana el envío más antiguo.
            Sleep::for($this->envios[0][0] + 60 - $ahora + 0.5)->seconds();
        }
    }

    /** Segundos con decimales; vía Carbon para que Sleep::fake() pueda adelantar el reloj en tests. */
    private function ahora(): float
    {
        return Carbon::now()->getPreciseTimestamp(6) / 1_000_000;
    }

    private function clave(string $texto): string
    {
        return 'embedding:'.sha1("{$this->provider->modelo()}|{$this->dimensions}|{$texto}");
    }
}
