<?php

namespace Tests\Feature;

use App\Enums\EstatusProcesamiento;
use App\Enums\EstatusVigencia;
use App\Enums\TipoDocumento;
use App\Filament\Resources\DocumentoReglamentos\Pages\CreateDocumentoReglamento;
use App\Filament\Resources\DocumentoReglamentos\Pages\EditDocumentoReglamento;
use App\Filament\Resources\DocumentoReglamentos\Pages\ListDocumentoReglamentos;
use App\Models\DocumentoReglamento;
use App\Models\Estado;
use App\Models\Municipio;
use App\Models\ReglamentoArticulo;
use App\Models\User;
use Database\Seeders\CatalogoTerritorialSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PanelReglamentosTest extends TestCase
{
    use RefreshDatabase;

    private const TEXTO = <<<'TXT'
        TÍTULO PRIMERO
        DISPOSICIONES GENERALES
        CAPÍTULO I
        ARTÍCULO 1.- Este Reglamento regula la circulación de vehículos y peatones.
        ARTÍCULO 2.- Para los efectos de este Reglamento se entiende por:
        I. Vehículo: todo medio de transporte terrestre;
        II. Peatón: la persona que transita a pie.
        CAPÍTULO II
        Artículo 15 Bis.- En zonas escolares la velocidad máxima será de 20 km/h.
        TRANSITORIOS
        PRIMERO.- Entra en vigor al día siguiente de su publicación.
        TXT;

    private Estado $oaxaca;

    private Municipio $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('rag.ingesta.disk'));
        $this->seed(CatalogoTerritorialSeeder::class);
        $this->actingAs(User::factory()->create());

        $this->oaxaca = Estado::where('clave_inegi', '20')->firstOrFail();
        $this->municipio = Municipio::where('slug', 'oaxaca-de-juarez')->firstOrFail();
    }

    public function test_el_listado_y_el_formulario_cargan(): void
    {
        $this->get('/admin')->assertOk();
        $this->get('/admin/documento-reglamentos')->assertOk();
        $this->get('/admin/documento-reglamentos/create')->assertOk();
    }

    public function test_crear_reglamento_municipal_con_texto_lo_procesa_y_hereda_el_territorio(): void
    {
        $documento = $this->crearDesdePanel(['titulo' => 'Reglamento de Tránsito']);

        $this->assertSame(EstatusProcesamiento::Completado, $documento->estatus_procesamiento);
        $this->assertSame(5, $documento->chunks_count);
        $this->assertSame(['1', '2', '15 Bis', 'Primero'], $documento->articulos()->orderBy('orden')->pluck('articulo')->filter()->unique()->values()->all());
        $this->assertSame(5, ReglamentoArticulo::where('municipio_id', $this->municipio->id)->where('estado_id', $this->oaxaca->id)->count());
        $this->assertSame(5, ReglamentoArticulo::aplicableA($this->oaxaca->id, $this->municipio->id)->count());
    }

    public function test_un_manual_se_divide_por_secciones_y_no_por_articulos(): void
    {
        $documento = $this->crearDesdePanel([
            'titulo' => 'Manual del Conductor 2023',
            'tipo' => 'manual',
            'nivel' => 'estatal',
            'municipio_id' => null,
            'texto' => "TEMA 3 SEÑALES DE TRÁNSITO\n\n3.2 Semáforos\nLa flecha roja indica que el movimiento señalado por ella está prohibido mientras permanezca encendida.\n\nARTÍCULO 5. Este texto citado dentro del manual no debe tratarse como artículo.",
        ]);

        $this->assertSame(TipoDocumento::Manual, $documento->tipo);
        $this->assertSame(EstatusProcesamiento::Completado, $documento->estatus_procesamiento);
        $this->assertSame([null], $documento->articulos()->distinct()->pluck('articulo')->all());
        $this->assertSame('3.2 Semáforos', $documento->articulos()->value('seccion'));
    }

    public function test_crear_reglamento_subiendo_archivo(): void
    {
        Livewire::test(CreateDocumentoReglamento::class)
            ->fillForm([
                'titulo' => 'Reglamento estatal',
                'nivel' => 'estatal',
                'estado_id' => $this->oaxaca->id,
                'anio' => 2025,
                'url_publica' => 'https://periodicooficial.oaxaca.gob.mx/reglamento.pdf',
                'fuente' => 'archivo',
                'archivo_path' => UploadedFile::fake()->createWithContent('reglamento.txt', self::TEXTO),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $documento = DocumentoReglamento::sole();
        $this->assertSame('reglamento.txt', $documento->archivo_nombre_original);
        $this->assertSame('text/plain', $documento->archivo_mime);
        $this->assertNull($documento->municipio_id);
        $this->assertSame(EstatusProcesamiento::Completado, $documento->fresh()->estatus_procesamiento);
        Storage::disk(config('rag.ingesta.disk'))->assertExists($documento->archivo_path);
    }

    public function test_municipal_sin_municipio_no_valida(): void
    {
        Livewire::test(CreateDocumentoReglamento::class)
            ->fillForm([
                'titulo' => 'Sin municipio',
                'nivel' => 'municipal',
                'estado_id' => $this->oaxaca->id,
                'anio' => 2025,
                'fuente' => 'texto',
                'texto' => self::TEXTO,
            ])
            ->call('create')
            ->assertHasFormErrors(['municipio_id' => 'required', 'url_publica' => 'required']);

        $this->assertSame(0, DocumentoReglamento::count());
    }

    public function test_una_reforma_deroga_al_documento_que_reemplaza_al_terminar(): void
    {
        $anterior = $this->crearDesdePanel(['titulo' => 'Reglamento 2020', 'anio' => 2020]);
        $reforma = $this->crearDesdePanel(['titulo' => 'Reglamento 2025', 'reemplaza_a_id' => $anterior->id]);

        $this->assertSame(EstatusVigencia::Derogado, $anterior->fresh()->estatus_vigencia);
        $this->assertSame(EstatusVigencia::Vigente, $reforma->estatus_vigencia);
        $this->assertSame(
            [$reforma->id],
            ReglamentoArticulo::aplicableA($this->oaxaca->id, $this->municipio->id)->distinct()->pluck('documento_id')->all(),
        );
    }

    public function test_derogar_desde_la_tabla_lo_saca_de_las_consultas(): void
    {
        $documento = $this->crearDesdePanel();

        Livewire::test(ListDocumentoReglamentos::class)
            ->callAction(TestAction::make('cambiarVigencia')->table($documento))
            ->assertNotified();

        $this->assertSame(EstatusVigencia::Derogado, $documento->fresh()->estatus_vigencia);
        $this->assertSame(0, ReglamentoArticulo::aplicableA($this->oaxaca->id, $this->municipio->id)->count());
    }

    public function test_reprocesar_desde_la_tabla_regenera_los_articulos(): void
    {
        $documento = $this->crearDesdePanel();
        $idsAntes = $documento->articulos()->pluck('id')->all();

        Livewire::test(ListDocumentoReglamentos::class)
            ->callAction(TestAction::make('reprocesar')->table($documento));

        $documento->refresh();
        $this->assertSame(EstatusProcesamiento::Completado, $documento->estatus_procesamiento);
        $this->assertSame(5, $documento->articulos()->count());
        $this->assertEmpty(array_intersect($idsAntes, $documento->articulos()->pluck('id')->all()));
    }

    public function test_cambiar_el_territorio_al_editar_actualiza_los_vectores(): void
    {
        $documento = $this->crearDesdePanel();
        $otro = Municipio::where('slug', 'santa-cruz-xoxocotlan')->firstOrFail();

        Livewire::test(EditDocumentoReglamento::class, ['record' => $documento->getRouteKey()])
            ->fillForm(['municipio_id' => $otro->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$otro->id], $documento->articulos()->distinct()->pluck('municipio_id')->all());
    }

    public function test_eliminar_borra_articulos_y_archivo(): void
    {
        $documento = $this->crearDesdePanel();

        Livewire::test(EditDocumentoReglamento::class, ['record' => $documento->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($documento);
        $this->assertSame(0, ReglamentoArticulo::count());
        Storage::disk(config('rag.ingesta.disk'))->assertMissing($documento->archivo_path);
    }

    public function test_la_api_rechaza_peticiones_sin_token(): void
    {
        $this->getJson('/api/admin/reglamentos')->assertUnauthorized();
        $this->getJson('/api/admin/reglamentos', ['Authorization' => 'Bearer token-de-prueba'])->assertOk();
    }

    private function crearDesdePanel(array $datos = []): DocumentoReglamento
    {
        Livewire::test(CreateDocumentoReglamento::class)
            ->fillForm([
                'titulo' => 'Reglamento de Tránsito',
                'nivel' => 'municipal',
                'estado_id' => $this->oaxaca->id,
                'municipio_id' => $this->municipio->id,
                'anio' => 2025,
                'url_publica' => 'https://periodicooficial.oaxaca.gob.mx/reglamento.pdf',
                'fuente' => 'texto',
                'texto' => self::TEXTO,
                ...$datos,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return DocumentoReglamento::latest('id')->firstOrFail();
    }
}
