<?php

namespace Tests\Feature;

use App\Filament\Resources\Ajustes\Pages\ListAjustesSitio;
use App\Filament\Resources\Colaboraciones\Pages\ListColaboraciones;
use App\Filament\Resources\Dinamicas\Pages\CreateDinamica;
use App\Filament\Resources\Dinamicas\Pages\ListDinamicas;
use App\Filament\Resources\Ganadores\Pages\ListGanadores;
use App\Filament\Resources\Videos\Pages\ListVideosDestacados;
use App\Models\AjusteSitio;
use App\Models\Colaboracion;
use App\Models\Dinamica;
use App\Models\Ganador;
use App\Models\User;
use App\Models\VideoDestacado;
use Database\Seeders\AjustesSitioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SitioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AjustesSitioSeeder::class);
    }

    public function test_devuelve_solo_contenido_visible_y_ganadores_con_consentimiento(): void
    {
        AjusteSitio::find('seguidores')->update(['valor' => '80500']);

        VideoDestacado::create(['titulo' => 'Glorieta', 'url' => 'https://facebook.com/v/1', 'miniatura' => 'sitio/videos/a.jpg']);
        VideoDestacado::create(['titulo' => 'Oculto', 'url' => 'https://facebook.com/v/2', 'visible' => false]);

        $terminada = Dinamica::create(['titulo' => 'Casco', 'premio' => 'Casco certificado', 'inicia_el' => now()->subMonth(), 'termina_el' => now()->subWeek()]);
        Dinamica::create(['titulo' => 'Activa', 'inicia_el' => now()->subDay(), 'termina_el' => now()->addWeek()]);
        Dinamica::create(['titulo' => 'Próxima', 'inicia_el' => now()->addWeek()]);

        Ganador::create(['dinamica_id' => $terminada->id, 'nombre' => 'Ana', 'consentimiento' => true]);
        Ganador::create(['dinamica_id' => $terminada->id, 'nombre' => 'Sin permiso']);

        Colaboracion::create(['nombre' => 'Escuela de manejo']);

        $this->getJson('/api/sitio')
            ->assertOk()
            ->assertJsonPath('comunidad.nombre', 'Andantes del Asfalto')
            ->assertJsonPath('estadisticas.seguidores', 80500)
            ->assertJsonPath('estadisticas.dinamicas', 2)
            ->assertJsonPath('estadisticas.premios_entregados', 2)
            ->assertJsonCount(1, 'videos')
            ->assertJsonPath('videos.0.miniatura', '/storage/sitio/videos/a.jpg')
            ->assertJsonPath('dinamicas.*.titulo', ['Activa', 'Próxima', 'Casco'])
            ->assertJsonPath('dinamicas.2.estatus', 'terminada')
            ->assertJsonPath('dinamicas.2.ganadores.*.nombre', ['Ana'])
            ->assertJsonPath('dinamicas.2.ganadores.0.premio', 'Casco certificado')
            ->assertJsonPath('dinamicas.0.ganadores', [])
            ->assertJsonPath('ganadores.0.dinamica_id', $terminada->id)
            ->assertJsonCount(1, 'ganadores')
            ->assertJsonPath('ganadores.0.nombre', 'Ana')
            ->assertJsonPath('ganadores.0.premio', 'Casco certificado')
            ->assertJsonCount(1, 'colaboraciones');
    }

    public function test_el_panel_muestra_y_crea_contenido_del_sitio(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([ListVideosDestacados::class, ListDinamicas::class, ListGanadores::class, ListColaboraciones::class, ListAjustesSitio::class] as $pagina) {
            Livewire::test($pagina)->assertOk();
        }

        Livewire::test(CreateDinamica::class)
            ->fillForm(['titulo' => 'Trivia vial', 'premio' => 'Kit de emergencia', 'inicia_el' => '2026-10-10', 'termina_el' => '2026-10-20'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('dinamicas', ['titulo' => 'Trivia vial', 'visible' => true]);
    }
}
