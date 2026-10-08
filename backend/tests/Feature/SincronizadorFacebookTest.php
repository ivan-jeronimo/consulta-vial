<?php

namespace Tests\Feature;

use App\Filament\Resources\Dinamicas\Pages\EditDinamica;
use App\Filament\Resources\PublicacionesFacebook\Pages\ListPublicacionesFacebook;
use App\Models\AjusteSitio;
use App\Models\Dinamica;
use App\Models\PublicacionFacebook;
use App\Models\User;
use App\Models\VideoDestacado;
use App\Services\Facebook\SincronizadorFacebook;
use Database\Seeders\AjustesSitioSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SincronizadorFacebookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AjustesSitioSeeder::class);
        Storage::fake('public');
        config(['services.facebook.page_id' => '123', 'services.facebook.page_token' => 'token-secreto']);
    }

    private int $seguidores = 81125;

    private ?array $instagram = null;

    private function fingirFacebook(): void
    {
        Http::fake(function (Request $request) {
            $ruta = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_starts_with($request->url(), 'https://cdn.fb/') => Http::response('jpg'),
                $ruta === '/v26.0/123' && str_contains($request['fields'] ?? '', 'instagram') => Http::response(['id' => '123', 'instagram_business_account' => $this->instagram]),
                $ruta === '/v26.0/123' => Http::response([
                    'followers_count' => $this->seguidores,
                    'picture' => ['data' => ['url' => 'https://cdn.fb/logo/a.jpg?oe=1']],
                    'cover' => ['source' => 'https://cdn.fb/portada/b.jpg?oe=1'],
                ]),
                $ruta === '/v26.0/123/videos' => Http::response(['data' => [
                    [
                        'id' => '111', 'permalink_url' => '/reel/111/', 'created_time' => '2026-10-01T03:00:00+0000',
                        'description' => "#motovlog\nUno por la izquierda 🏍️ #andantes\notra línea",
                        'thumbnails' => ['data' => [['uri' => 'https://cdn.fb/t/1.jpg', 'is_preferred' => false], ['uri' => 'https://cdn.fb/t/2.jpg', 'is_preferred' => true]]],
                        'video_insights' => ['data' => [['name' => 'fb_reels_total_plays', 'values' => [['value' => 18332]]]]],
                    ],
                    [
                        'id' => '222', 'permalink_url' => '/AndantesDelAsfalto/videos/222/', 'created_time' => '2026-09-01T03:00:00+0000',
                        'title' => 'Ruta a la costa',
                        'video_insights' => ['data' => [['name' => 'total_video_views', 'values' => [['value' => 500]]]]],
                    ],
                ]]),
                $ruta === '/v26.0/222' => Http::response(['thumbnails' => ['data' => [['uri' => 'https://cdn.fb/t/222.jpg', 'is_preferred' => true]]]]),
                $ruta === '/v26.0/123/posts' => Http::response(['data' => [
                    ['id' => '123_1', 'message' => "¿Y si tu próximo casco te lo regalamos? 👀\n#Dinámica #AndantesDelAsfalto", 'created_time' => '2026-09-13T12:00:00+0000', 'permalink_url' => 'https://facebook.com/p/1', 'full_picture' => 'https://cdn.fb/p/1.jpg'],
                    ['id' => '123_2', 'message' => 'Video del fin de semana #dinamicas2025', 'created_time' => '2026-09-14T12:00:00+0000'],
                    ['id' => '123_3', 'created_time' => '2026-09-15T12:00:00+0000'],
                ]]),
                default => Http::response(['error' => ['message' => 'ruta no esperada '.$ruta]], 404),
            };
        });
    }

    public function test_trae_pagina_videos_y_publicaciones_con_dinamica(): void
    {
        $this->fingirFacebook();

        $resumen = app(SincronizadorFacebook::class)->sincronizar();

        $this->assertSame(['seguidores' => 81125, 'instagram' => null, 'videos' => 2, 'publicaciones_nuevas' => 1], $resumen);
        $this->assertNull(AjusteSitio::valor('instagram_usuario'));
        $this->assertSame('81125', AjusteSitio::valor('seguidores'));
        Storage::disk('public')->assertExists(AjusteSitio::valor('logo'));
        Storage::disk('public')->assertExists(AjusteSitio::valor('portada'));
        $this->assertNotNull(AjusteSitio::valor('facebook_sincronizado_at'));

        $reel = VideoDestacado::where('facebook_id', '111')->sole();
        $this->assertSame('reel', $reel->tipo);
        $this->assertSame(18332, $reel->vistas);
        $this->assertSame('Uno por la izquierda 🏍️', $reel->titulo);
        $this->assertSame('https://www.facebook.com/reel/111/', $reel->url);
        $this->assertSame('sitio/facebook/videos/111.jpg', $reel->miniatura);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://cdn.fb/t/2.jpg');

        $video = VideoDestacado::where('facebook_id', '222')->sole();
        $this->assertSame(['video', 500, 'Ruta a la costa'], [$video->tipo, $video->vistas, $video->titulo]);

        $publicacion = PublicacionFacebook::sole();
        $this->assertSame(['123_1', 'pendiente'], [$publicacion->facebook_id, $publicacion->estado]);
        Storage::disk('public')->assertExists($publicacion->imagen);

        // El sitio los muestra del más visto al menos visto, con el reproductor de Facebook.
        $this->getJson('/api/sitio')
            ->assertJsonPath('estadisticas.seguidores', 81125)
            ->assertJsonPath('comunidad.logo', '/storage/'.AjusteSitio::valor('logo'))
            ->assertJsonPath('videos.*.titulo', ['Uno por la izquierda 🏍️', 'Ruta a la costa'])
            ->assertJsonPath('videos.0.embed', 'https://www.facebook.com/plugins/video.php?href='.urlencode('https://www.facebook.com/123/videos/111/').'&show_text=false&autoplay=true');
    }

    public function test_respeta_lo_editado_en_el_panel_y_no_duplica(): void
    {
        $this->fingirFacebook();
        app(SincronizadorFacebook::class)->sincronizar();

        VideoDestacado::where('facebook_id', '222')->update(['titulo' => 'Mi título', 'visible' => false, 'fijado' => true]);
        PublicacionFacebook::sole()->update(['estado' => 'ignorada']);

        $this->seguidores = 82000;
        $resumen = app(SincronizadorFacebook::class)->sincronizar();

        $this->assertSame(0, $resumen['publicaciones_nuevas']);
        $this->assertSame(2, VideoDestacado::count());
        $this->assertSame('ignorada', PublicacionFacebook::sole()->estado);
        $video = VideoDestacado::where('facebook_id', '222')->sole();
        $this->assertSame(['Mi título', false, true], [$video->titulo, $video->visible, $video->fijado]);
        $this->assertSame('82000', AjusteSitio::valor('seguidores'));
    }

    public function test_un_error_de_facebook_queda_registrado_sin_exponer_el_token(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired']], 400)]);

        try {
            app(SincronizadorFacebook::class)->sincronizar();
            $this->fail('Debió lanzar excepción');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Session has expired', $e->getMessage());
        }

        $this->assertStringContainsString('Session has expired', AjusteSitio::valor('facebook_error'));
        $this->assertStringNotContainsString('token-secreto', AjusteSitio::valor('facebook_error'));
    }

    public function test_crear_dinamica_desde_una_publicacion(): void
    {
        $this->fingirFacebook();
        app(SincronizadorFacebook::class)->sincronizar();
        $publicacion = PublicacionFacebook::sole();

        $this->actingAs(User::factory()->create());
        Livewire::test(ListPublicacionesFacebook::class)
            ->assertCanSeeTableRecords([$publicacion])
            ->callAction(TestAction::make('crearDinamica')->table($publicacion))
            ->assertRedirect();

        $publicacion->refresh();
        $this->assertSame('usada', $publicacion->estado);
        $this->assertSame('¿Y si tu próximo casco te lo regalamos? 👀', $publicacion->dinamica->titulo);
        $this->assertSame('2026-09-13', $publicacion->dinamica->inicia_el->toDateString());
        $this->assertSame($publicacion->imagen, $publicacion->dinamica->imagen);
    }

    public function test_videos_de_las_etapas_de_una_dinamica(): void
    {
        $this->fingirFacebook();
        app(SincronizadorFacebook::class)->sincronizar();
        $dinamica = Dinamica::create(['titulo' => 'Casco', 'inicia_el' => '2026-09-01', 'termina_el' => '2026-09-20']);
        $inicio = VideoDestacado::where('facebook_id', '222')->sole(); // sin miniatura todavía
        $enVivo = VideoDestacado::where('facebook_id', '111')->sole();

        $this->actingAs(User::factory()->create());
        Livewire::test(EditDinamica::class, ['record' => $dinamica->id])
            ->fillForm(['video_inicio_id' => $inicio->id, 'video_en_vivo_id' => $enVivo->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sitio/facebook/videos/222.jpg', $inicio->fresh()->miniatura);

        $this->getJson('/api/sitio')
            ->assertJsonPath('dinamicas.0.video_inicio.titulo', 'Ruta a la costa')
            ->assertJsonPath('dinamicas.0.video_inicio.miniatura', '/storage/sitio/facebook/videos/222.jpg')
            ->assertJsonPath('dinamicas.0.video_en_vivo.vistas', 18332)
            ->assertJsonPath('dinamicas.0.video_cierre', null);
    }
}
