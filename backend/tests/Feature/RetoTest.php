<?php

namespace Tests\Feature;

use App\Models\Jugador;
use App\Models\Partida;
use App\Models\PreguntaReto;
use App\Services\Reto\Juego;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.facebook.app_id' => 'app-123',
            'services.facebook.app_secret' => 'secreto',
            'services.facebook.sitio_url' => 'http://sitio.test',
        ]);

        foreach (range(1, 12) as $n) {
            PreguntaReto::create([
                'categoria' => 'General', 'pregunta' => "Pregunta {$n}", 'estado' => 'aprobada',
                'opciones' => ["Correcta {$n}", 'Incorrecta A', 'Incorrecta B', 'Incorrecta C'], 'correcta' => 0,
                'explicacion' => "Porque sí {$n}",
            ]);
        }
        PreguntaReto::create(['categoria' => 'General', 'pregunta' => 'Borrador', 'opciones' => ['a', 'b', 'c'], 'correcta' => 0]);
    }

    private function jugador(array $datos = []): Jugador
    {
        return Jugador::create(['facebook_id' => (string) random_int(1, 1e9), 'nombre' => 'Ana López', 'publico' => true, ...$datos]);
    }

    /** Índice (en el orden mostrado) de la opción correcta o de una incorrecta. */
    private function opcion(string $partidaId, bool $correcta): int
    {
        $partida = Partida::findOrFail($partidaId);
        ['id' => $id, 'orden' => $orden] = $partida->preguntas[$partida->actual];
        $buena = PreguntaReto::find($id)->correcta;

        return collect($orden)->search(fn (int $i) => $correcta ? $i === $buena : $i !== $buena);
    }

    public function test_iniciar_sesion_con_facebook(): void
    {
        $this->get('/auth/facebook')
            ->assertRedirectContains('https://www.facebook.com/v26.0/dialog/oauth')
            ->assertRedirectContains(urlencode('http://sitio.test/auth/facebook/callback'));
        $state = session('facebook_state');

        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), 'oauth/access_token') => Http::response(['access_token' => 'tok']),
            str_contains($r->url(), '/me') => Http::response([
                'id' => '987', 'name' => 'Ana López', 'email' => 'ana@example.com',
                'picture' => ['data' => ['url' => 'https://cdn.fb/foto.jpg', 'is_silhouette' => false]],
            ]),
            default => Http::response('jpg'),
        });

        $this->get("/auth/facebook/callback?code=abc&state={$state}")->assertRedirect('http://sitio.test/#reto');

        $jugador = Jugador::sole();
        $this->assertSame(['987', 'Ana López', null], [$jugador->facebook_id, $jugador->nombre, $jugador->publico]);
        $this->assertAuthenticatedAs($jugador, 'jugador');
        Storage::disk('public')->assertExists($jugador->foto);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me') && $r['appsecret_proof'] === hash_hmac('sha256', 'tok', 'secreto'));

        $this->getJson('/api/reto/yo')->assertJsonPath('jugador.nombre', 'Ana López')->assertJsonMissingPath('jugador.email');
    }

    public function test_un_state_que_no_coincide_no_inicia_sesion(): void
    {
        $this->get('/auth/facebook');

        $this->get('/auth/facebook/callback?code=abc&state=otro')->assertRedirect('http://sitio.test/?login=error#reto');
        $this->get('/auth/facebook/callback?error=access_denied')->assertRedirect('http://sitio.test/?login=cancelado#reto');
        $this->assertGuest('jugador');
    }

    public function test_partida_completa_con_puntos_por_acierto_y_rapidez(): void
    {
        $this->actingAs($jugador = $this->jugador(), 'jugador');

        $inicio = $this->postJson('/api/reto/partidas')->assertCreated()
            ->assertJsonPath('pregunta.numero', 1)
            ->assertJsonPath('pregunta.total', Juego::PREGUNTAS)
            ->assertJsonCount(4, 'pregunta.opciones')
            ->assertJsonMissingPath('pregunta.correcta');
        $id = $inicio->json('partida');
        $this->assertStringNotContainsString('Borrador', $inicio->getContent());

        // 1: correcta al instante → 150. 2: correcta a la mitad del tiempo → 125. 3: incorrecta → 0.
        $this->postJson("/api/reto/partidas/{$id}/respuestas", ['opcion' => $this->opcion($id, true)])
            ->assertOk()->assertJsonPath('correcta', true)->assertJsonPath('puntos', 150)->assertJsonPath('siguiente.numero', 2);

        $this->getJson("/api/reto/partidas/{$id}"); // se muestra la 2 y empieza su reloj
        $this->travel(10)->seconds();
        $this->postJson("/api/reto/partidas/{$id}/respuestas", ['opcion' => $this->opcion($id, true)])->assertJsonPath('puntos', 125);

        $correcta = $this->opcion($id, true);
        $this->postJson("/api/reto/partidas/{$id}/respuestas", ['opcion' => $this->opcion($id, false)])
            ->assertJsonPath('correcta', false)->assertJsonPath('puntos', 0)->assertJsonPath('opcion_correcta', $correcta);

        // 4: fuera de tiempo aunque elija la correcta.
        $this->getJson("/api/reto/partidas/{$id}");
        $this->travel(30)->seconds();
        $this->postJson("/api/reto/partidas/{$id}/respuestas", ['opcion' => $this->opcion($id, true)])
            ->assertJsonPath('fuera_de_tiempo', true)->assertJsonPath('puntos', 0);

        foreach (range(5, 10) as $n) {
            $ultima = $this->postJson("/api/reto/partidas/{$id}/respuestas", ['opcion' => $this->opcion($id, true)]);
        }

        $ultima->assertJsonPath('terminada', true)
            ->assertJsonPath('siguiente', null)
            ->assertJsonPath('aciertos', 8)
            ->assertJsonPath('puntos_partida', 150 + 125 + 6 * 150)
            ->assertJsonPath('mes.posicion', 1);

        $this->postJson("/api/reto/partidas/{$id}/respuestas", ['opcion' => 0])->assertStatus(409);
        $this->assertSame(10, Partida::find($id)->respuestas()->count());
    }

    public function test_ranking_mensual_con_la_mejor_partida_de_quienes_aceptaron_aparecer(): void
    {
        $ana = $this->jugador(['nombre' => 'Ana']);
        $beto = $this->jugador(['nombre' => 'Beto']);
        $oculto = $this->jugador(['nombre' => 'Oculto', 'publico' => false]);
        $terminada = fn (Jugador $j, int $puntos, $cuando = null) => $j->partidas()->create(['preguntas' => [], 'puntos' => $puntos, 'aciertos' => intdiv($puntos, 150), 'terminada_at' => $cuando ?? now()]);

        $terminada($ana, 900);
        $terminada($ana, 1200);
        $terminada($beto, 1000);
        $terminada($oculto, 1500);
        $terminada($beto, 1500, now()->subMonth()); // del mes pasado: no cuenta
        $beto->partidas()->create(['preguntas' => [], 'puntos' => 1400]); // sin terminar: no cuenta

        $this->getJson('/api/reto/ranking')
            ->assertJsonPath('jugadores.*.nombre', ['Ana', 'Beto'])
            ->assertJsonPath('jugadores.0.puntos', 1200)
            ->assertJsonPath('jugadores.1.posicion', 2);

        $this->actingAs($oculto, 'jugador');
        $this->getJson('/api/reto/yo')->assertJsonPath('mes.puntos', 1500)->assertJsonPath('mes.posicion', null);
    }

    public function test_reglas_de_acceso(): void
    {
        $this->postJson('/api/reto/partidas')->assertUnauthorized();

        $this->actingAs($this->jugador(), 'jugador');
        $ajena = $this->jugador()->partidas()->create(['preguntas' => [['id' => 1, 'orden' => [0, 1, 2, 3]]]]);
        $this->getJson("/api/reto/partidas/{$ajena->id}")->assertNotFound();

        PreguntaReto::query()->update(['estado' => 'borrador']);
        $this->postJson('/api/reto/partidas')->assertStatus(409);
    }

    public function test_preferencias_y_borrar_mis_datos(): void
    {
        $jugador = $this->jugador(['publico' => null, 'foto' => 'sitio/jugadores/1.jpg']);
        Storage::disk('public')->put('sitio/jugadores/1.jpg', 'jpg');
        $this->actingAs($jugador, 'jugador');

        $this->postJson('/api/reto/preferencias', ['publico' => false])->assertOk();
        $this->assertFalse($jugador->fresh()->publico);

        $jugador->partidas()->create(['preguntas' => []]);
        $this->postJson('/api/reto/borrar-cuenta')->assertOk();

        $this->assertDatabaseCount('jugadores', 0);
        $this->assertDatabaseCount('partidas', 0);
        Storage::disk('public')->assertMissing('sitio/jugadores/1.jpg');
    }
}
