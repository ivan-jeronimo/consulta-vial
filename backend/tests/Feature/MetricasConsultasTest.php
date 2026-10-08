<?php

namespace Tests\Feature;

use App\Filament\Resources\TemasConsulta\Pages\ListTemasConsulta;
use App\Models\Consulta;
use App\Models\Municipio;
use App\Models\TemaConsulta;
use App\Models\User;
use App\Services\Embeddings\EmbeddingProvider;
use App\Services\Ingesta\RegistroReglamentos;
use Database\Seeders\CatalogoTerritorialSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MetricasConsultasTest extends TestCase
{
    use RefreshDatabase;

    private Municipio $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer(); // el registro de métricas corre en el mismo request
        Storage::fake(config('rag.ingesta.disk'));
        $this->seed(CatalogoTerritorialSeeder::class);
        $this->municipio = Municipio::where('slug', 'oaxaca-de-juarez')->firstOrFail();

        // Embeddings de prueba: preguntas con la misma intención (palabra clave)
        // dan vectores casi iguales; intenciones distintas, ortogonales.
        $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
        {
            public function embed(array $textos, bool $consulta = false): array
            {
                return array_map(function (string $t) {
                    $v = array_fill(0, 1536, 0.0);
                    $t = mb_strtolower($t);
                    $v[str_contains($t, 'flecha') ? 0 : (str_contains($t, 'casco') ? 1 : 2)] = 1.0;
                    $v[3 + strlen($t) % 50] = 0.1; // pequeña variación por redacción

                    return $v;
                }, $textos);
            }

            public function modelo(): string
            {
                return 'prueba';
            }
        });

        $registro = app(RegistroReglamentos::class);
        $registro->registrar(
            ['titulo' => 'Reglamento Municipal', 'nivel' => 'municipal', 'estado_id' => $this->municipio->estado_id, 'municipio_id' => $this->municipio->id, 'anio' => 2026],
            $registro->guardarTexto("ARTÍCULO 54. La vuelta a la derecha siempre será continua, excepto con señales restrictivas como la flecha roja.\nARTÍCULO 60. Los motociclistas deberán usar casco."),
        );
    }

    private function preguntar(string $pregunta, ?string $visitante = null): string
    {
        return $this->postJson('/api/consulta', array_filter([
            'municipio_id' => $this->municipio->id,
            'pregunta' => $pregunta,
            'visitante' => $visitante,
        ]))->assertOk()->json('consulta_id');
    }

    public function test_registra_la_consulta_sin_datos_personales(): void
    {
        $id = $this->preguntar('¿Puedo dar vuelta a la derecha con flecha roja?', '0b7e4c1a-1111-4a2b-9c3d-000000000001');

        $consulta = Consulta::findOrFail($id);
        $this->assertSame('¿Puedo dar vuelta a la derecha con flecha roja?', $consulta->pregunta);
        $this->assertSame($this->municipio->id, $consulta->municipio_id);
        $this->assertSame('0b7e4c1a-1111-4a2b-9c3d-000000000001', $consulta->visitante);
        $this->assertNotNull($consulta->latencia_ms);
        $this->assertArrayNotHasKey('ip', $consulta->getAttributes());
    }

    public function test_agrupa_preguntas_con_la_misma_intencion_en_un_tema(): void
    {
        $this->preguntar('¿Puedo dar vuelta a la derecha con flecha roja?');
        $this->preguntar('si hay flecha roja me puedo seguir a la derecha');
        $this->preguntar('¿Es obligatorio el casco en moto?');

        $this->assertSame(2, TemaConsulta::count());
        $flecha = TemaConsulta::where('etiqueta', '¿Puedo dar vuelta a la derecha con flecha roja?')->sole();
        $this->assertSame(2, $flecha->consultas()->count());
    }

    public function test_valoracion_de_una_respuesta(): void
    {
        $id = $this->preguntar('¿Es obligatorio el casco en moto?');

        $this->postJson("/api/consultas/{$id}/valoracion", ['util' => false])->assertOk();
        $this->assertSame(-1, Consulta::findOrFail($id)->valoracion);

        $this->postJson('/api/consultas/no-es-un-uuid/valoracion', ['util' => true])->assertNotFound();
    }

    public function test_el_tablero_y_las_secciones_de_metricas_cargan(): void
    {
        $this->preguntar('¿Puedo dar vuelta a la derecha con flecha roja?');
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk();
        $this->get('/admin/temas')->assertOk()->assertSee('¿Puedo dar vuelta a la derecha con flecha roja?');
        $this->get('/admin/consultas')->assertOk();
        $this->get('/admin/temas/'.TemaConsulta::sole()->id.'/edit')->assertOk();
    }

    public function test_los_widgets_del_tablero_muestran_los_datos_con_filtros(): void
    {
        $this->preguntar('¿Puedo dar vuelta a la derecha con flecha roja?', '0b7e4c1a-1111-4a2b-9c3d-000000000001');
        $this->preguntar('si hay flecha roja me puedo seguir a la derecha', '0b7e4c1a-1111-4a2b-9c3d-000000000002');
        $this->actingAs(User::factory()->create());
        $filtros = ['periodo' => 7, 'estado_id' => $this->municipio->estado_id, 'municipio_id' => $this->municipio->id];

        Livewire::test(\App\Filament\Widgets\ResumenConsultas::class, ['pageFilters' => $filtros])
            ->assertSee('Consultas')
            ->assertSee('Usuarios únicos');
        Livewire::test(\App\Filament\Widgets\ConsultasPorDia::class, ['pageFilters' => $filtros])->assertOk();
        Livewire::test(\App\Filament\Widgets\ConsultasPorUbicacion::class, ['pageFilters' => $filtros])->assertOk();
        Livewire::test(\App\Filament\Widgets\TemasMasConsultados::class, ['pageFilters' => $filtros])
            ->assertCanSeeTableRecords(TemaConsulta::all())
            ->assertSee('¿Puedo dar vuelta a la derecha con flecha roja?');
        Livewire::test(\App\Filament\Widgets\TemasSinCobertura::class, ['pageFilters' => $filtros])->assertOk();

        // Otro municipio: no hay consultas que mostrar.
        $otro = Municipio::where('slug', 'santa-cruz-xoxocotlan')->firstOrFail();
        Livewire::test(\App\Filament\Widgets\TemasMasConsultados::class, ['pageFilters' => [...$filtros, 'municipio_id' => $otro->id]])
            ->assertCountTableRecords(0);
    }

    public function test_unir_temas_pasa_sus_consultas_al_mas_consultado(): void
    {
        $this->preguntar('¿Puedo dar vuelta a la derecha con flecha roja?');
        $this->preguntar('si hay flecha roja me puedo seguir a la derecha');
        $this->preguntar('¿Es obligatorio el casco en moto?');
        $this->actingAs(User::factory()->create());

        Livewire::test(ListTemasConsulta::class)
            ->selectTableRecords(TemaConsulta::pluck('id')->all())
            ->callAction(TestAction::make('unir')->table()->bulk());

        $this->assertSame(1, TemaConsulta::count());
        $this->assertSame(3, TemaConsulta::sole()->consultas()->count());
        $this->assertStringContainsString('flecha roja', TemaConsulta::sole()->etiqueta);
    }
}
