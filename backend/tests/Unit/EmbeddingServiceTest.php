<?php

namespace Tests\Unit;

use App\Services\Embeddings\EmbeddingProvider;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EmbeddingServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Proveedor que registra cuántos textos recibe y puede fallar en la N-ésima llamada. */
    private function proveedor(?int $fallarEnLlamada = null): EmbeddingProvider
    {
        return new class($fallarEnLlamada) implements EmbeddingProvider
        {
            public array $lotes = [];

            public function __construct(private ?int $fallarEnLlamada) {}

            public function embed(array $textos, bool $consulta = false): array
            {
                if (count($this->lotes) + 1 === $this->fallarEnLlamada) {
                    $this->fallarEnLlamada = null;
                    throw new RuntimeException('429 simulado');
                }

                $this->lotes[] = count($textos);

                return array_map(fn (string $t) => [(float) strlen($t), 1.0, 0.5], $textos);
            }

            public function modelo(): string
            {
                return 'modelo-prueba';
            }
        };
    }

    public function test_un_reintento_retoma_desde_los_vectores_ya_calculados(): void
    {
        $cache = new Repository(new ArrayStore);
        $proveedor = $this->proveedor(fallarEnLlamada: 3);
        $textos = array_map(fn ($i) => "texto número {$i}", range(1, 10));

        $servicio = fn () => new EmbeddingService($proveedor, batchSize: 3, dimensions: 3, cache: $cache);

        try {
            $servicio()->embedEnLotes($textos);
            $this->fail('Debía fallar en el tercer lote.');
        } catch (RuntimeException) {
        }

        $this->assertSame([3, 3], $proveedor->lotes, 'Antes del fallo se calcularon 6 textos.');

        $vectores = $servicio()->embedEnLotes($textos);

        $this->assertSame([3, 3, 3, 1], $proveedor->lotes, 'El reintento solo pide los 4 que faltaban.');
        $this->assertCount(10, $vectores);
        $this->assertSame((float) strlen('texto número 1'), $vectores[0][0], 'Se conserva el orden original.');
        $this->assertSame((float) strlen('texto número 10'), $vectores[9][0]);
    }

    public function test_respeta_el_limite_de_textos_por_minuto(): void
    {
        Carbon::setTestNow(Carbon::now());
        Sleep::fake(syncWithCarbon: true);
        $proveedor = $this->proveedor();
        $servicio = new EmbeddingService($proveedor, batchSize: 96, dimensions: 3, cache: new Repository(new ArrayStore), textosPorMinuto: 80);

        $servicio->embedEnLotes(array_map(fn ($i) => "t{$i}", range(1, 200)));

        $this->assertSame([80, 80, 40], $proveedor->lotes, 'Los lotes no superan el límite por minuto.');
        Sleep::assertSleptTimes(2);
    }
}
