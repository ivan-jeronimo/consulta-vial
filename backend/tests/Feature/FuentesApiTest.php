<?php

namespace Tests\Feature;

use App\Models\DocumentoReglamento;
use App\Models\Estado;
use App\Services\Ingesta\RegistroReglamentos;
use Database\Seeders\CatalogoTerritorialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FuentesApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUIA = <<<'TXT'
        I.-CONOCIMIENTOS GENERALES
        ● CUANDO UN CONDUCTOR QUE CIRCULA DETRÁS DE USTED LO PRETENDE REBASAR, USTED DEBE BAJAR LA VELOCIDAD.
        II.-SEÑALES DE TRÁNSITO
        ● LAS SEÑALES RESTRICTIVAS TIENEN FORMA CUADRADA CON UN ANILLO ROJO.
        TXT;

    private DocumentoReglamento $guia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('rag.ingesta.disk'));
        $this->seed(CatalogoTerritorialSeeder::class);
        config(['rag.fuentes_tokens' => ['token-andantes', 'token-otro-cliente']]);

        $registro = app(RegistroReglamentos::class);
        $this->guia = $registro->registrar(
            ['titulo' => 'Guía de Examen de Manejo Oaxaca 2023', 'tipo' => 'manual', 'nivel' => 'estatal', 'estado_id' => Estado::where('clave_inegi', '20')->value('id'), 'anio' => 2023],
            $registro->guardarTexto(self::GUIA),
        )->refresh();
    }

    public function test_lista_documentos_vigentes_con_sus_capitulos(): void
    {
        $this->getJson('/api/fuentes/documentos', ['Authorization' => 'Bearer token-otro-cliente'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->guia->id)
            ->assertJsonPath('data.0.tipo', 'manual')
            ->assertJsonPath('data.0.capitulos', ['I.-CONOCIMIENTOS GENERALES', 'II.-SEÑALES DE TRÁNSITO']);
    }

    public function test_devuelve_los_fragmentos_en_orden_y_filtra_por_capitulo(): void
    {
        $cabecera = ['Authorization' => 'Bearer token-andantes'];

        $todos = $this->getJson("/api/fuentes/documentos/{$this->guia->id}/fragmentos", $cabecera)->assertOk()->json('data');
        $this->assertSame(array_column($todos, 'orden'), collect($todos)->pluck('orden')->sort()->values()->all());
        $this->assertTrue(collect($todos)->contains(fn ($f) => str_contains($f['contenido'], 'PRETENDE REBASAR')));

        $this->getJson("/api/fuentes/documentos/{$this->guia->id}/fragmentos?capitulo=".urlencode('II.-SEÑALES DE TRÁNSITO'), $cabecera)
            ->assertOk()
            ->assertJsonMissingPath('data.*.embedding')
            ->assertJsonPath('data.*.capitulo', fn (array $capitulos) => $capitulos !== [] && array_unique($capitulos) === ['II.-SEÑALES DE TRÁNSITO']);
    }

    public function test_requiere_un_token_de_cliente_y_solo_lee_documentos_vigentes(): void
    {
        $this->getJson('/api/fuentes/documentos')->assertUnauthorized();
        $this->getJson('/api/fuentes/documentos', ['Authorization' => 'Bearer otro'])->assertUnauthorized();

        // El token de administración no sirve aquí, ni el de cliente para administrar.
        $this->getJson('/api/fuentes/documentos', ['Authorization' => 'Bearer '.config('rag.admin_token')])->assertUnauthorized();
        $this->getJson('/api/admin/reglamentos', ['Authorization' => 'Bearer token-andantes'])->assertUnauthorized();

        $this->guia->update(['estatus_vigencia' => 'derogado']);
        $this->getJson("/api/fuentes/documentos/{$this->guia->id}/fragmentos", ['Authorization' => 'Bearer token-andantes'])->assertNotFound();
        $this->getJson('/api/fuentes/documentos', ['Authorization' => 'Bearer token-andantes'])->assertJsonCount(0, 'data');
    }
}
