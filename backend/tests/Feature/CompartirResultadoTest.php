<?php

namespace Tests\Feature;

use App\Models\Jugador;
use App\Models\Partida;
use App\Services\Reto\TarjetaResultado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompartirResultadoTest extends TestCase
{
    use RefreshDatabase;

    private Partida $partida;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.facebook.sitio_url' => 'https://andantes.test']);

        $jugador = Jugador::create(['facebook_id' => '1', 'nombre' => 'Ivan Jeronimo Pérez', 'publico' => true]);
        $this->partida = $jugador->partidas()->create([
            'preguntas' => array_fill(0, 10, ['id' => 1, 'orden' => [0, 1, 2, 3]]),
            'puntos' => 1275, 'aciertos' => 9, 'terminada_at' => now(),
        ]);
    }

    public function test_los_robots_de_las_redes_reciben_la_vista_previa(): void
    {
        $this->get("/r/{$this->partida->id}", ['User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'])
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Ivan J. hizo 1,275 puntos en El reto del asfalto">', false)
            ->assertSee('9 de 10 correctas', false)
            ->assertSee("https://andantes.test/r/{$this->partida->id}/imagen.png", false)
            ->assertDontSee('Jeronimo');
    }

    public function test_las_personas_van_directo_al_reto(): void
    {
        $this->get("/r/{$this->partida->id}", ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'])
            ->assertRedirect('https://andantes.test/?desde=compartido#reto');
    }

    public function test_partidas_sin_terminar_o_inexistentes_no_se_comparten(): void
    {
        $this->partida->update(['terminada_at' => null]);

        $this->get("/r/{$this->partida->id}")->assertNotFound();
        $this->get("/r/{$this->partida->id}/imagen.png")->assertNotFound();
        $this->get('/r/no-es-un-uuid')->assertNotFound();
    }

    public function test_genera_la_imagen_una_sola_vez(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('El contenedor no tiene la extensión gd.');
        }

        $this->get("/r/{$this->partida->id}/imagen.png")->assertOk()->assertHeader('Content-Type', 'image/png');

        $ruta = "sitio/compartir/{$this->partida->id}.png";
        Storage::disk('public')->assertExists($ruta);
        [$ancho, $alto] = getimagesizefromstring(Storage::disk('public')->get($ruta));
        $this->assertSame([1200, 630], [$ancho, $alto]);
    }

    public function test_al_terminar_la_partida_la_api_da_el_enlace(): void
    {
        $this->assertSame('Ivan J.', TarjetaResultado::nombreCorto('Ivan Jeronimo Pérez'));
        $this->assertSame('Ivan', TarjetaResultado::nombreCorto('Ivan'));
    }
}
