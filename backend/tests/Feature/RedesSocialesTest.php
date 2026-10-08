<?php

namespace Tests\Feature;

use App\Filament\Resources\Ajustes\Pages\EditAjusteSitio;
use App\Models\AjusteSitio;
use App\Models\User;
use App\Services\Facebook\SincronizadorFacebook;
use App\Services\Redes\SincronizadorYoutube;
use Database\Seeders\AjustesSitioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RedesSocialesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AjustesSitioSeeder::class);
        config(['services.youtube.key' => 'clave-secreta']);
    }

    public function test_el_sitio_suma_los_seguidores_de_todas_las_redes(): void
    {
        AjusteSitio::fijar('seguidores', '81125');
        AjusteSitio::fijar('seguidores_instagram', '12000');
        AjusteSitio::fijar('url_tiktok', 'https://www.tiktok.com/@andantes');
        AjusteSitio::fijar('seguidores_tiktok', '5400');

        $this->getJson('/api/sitio')
            ->assertJsonPath('estadisticas.seguidores', 98525)
            // YouTube no aparece: no tiene enlace ni número.
            ->assertJsonPath('comunidad.redes.*.red', ['facebook', 'instagram', 'tiktok'])
            ->assertJsonPath('comunidad.redes.2', ['red' => 'tiktok', 'url' => 'https://www.tiktok.com/@andantes', 'seguidores' => 5400]);
    }

    public function test_youtube_trae_los_suscriptores_del_canal(): void
    {
        AjusteSitio::fijar('url_youtube', 'https://www.youtube.com/@AndantesDelAsfalto/videos');
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => [['statistics' => ['subscriberCount' => '2310', 'hiddenSubscriberCount' => false]]]])]);

        $this->assertSame(2310, app(SincronizadorYoutube::class)->sincronizar());

        $this->assertSame('2310', AjusteSitio::valor('seguidores_youtube'));
        Http::assertSent(fn (Request $r) => $r['forHandle'] === '@AndantesDelAsfalto' && $r['part'] === 'statistics');
        $this->assertTrue(AjusteSitio::find('seguidores_youtube')->esAutomatico());
    }

    public function test_un_error_de_youtube_queda_registrado_sin_la_clave(): void
    {
        AjusteSitio::fijar('url_youtube', 'https://www.youtube.com/channel/UC123abc');
        AjusteSitio::fijar('seguidores_youtube', '2000');
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 400)]);

        $this->assertNull(app(SincronizadorYoutube::class)->sincronizar());

        $this->assertStringContainsString('API key not valid', AjusteSitio::valor('youtube_error'));
        $this->assertStringNotContainsString('clave-secreta', AjusteSitio::valor('youtube_error'));
        $this->assertSame('2000', AjusteSitio::valor('seguidores_youtube')); // conserva el último número bueno
    }

    public function test_instagram_vinculado_a_la_pagina_se_sincroniza_y_deja_de_editarse_a_mano(): void
    {
        Storage::fake('public');
        config(['services.facebook.page_id' => '123', 'services.facebook.page_token' => 'token']);
        $this->assertFalse(AjusteSitio::find('seguidores_instagram')->esAutomatico());

        Http::fake(fn (Request $r) => match (true) {
            str_contains($r['fields'] ?? '', 'instagram') => Http::response(['instagram_business_account' => ['id' => '9', 'username' => 'andantesdelasfalto', 'followers_count' => 12034]]),
            default => Http::response(['data' => []]),
        });

        app(SincronizadorFacebook::class)->sincronizar();

        $this->assertSame('12034', AjusteSitio::valor('seguidores_instagram'));
        $this->assertSame('https://www.instagram.com/andantesdelasfalto/', AjusteSitio::valor('url_instagram'));
        $this->assertTrue(AjusteSitio::find('seguidores_instagram')->esAutomatico());
    }

    public function test_sin_permiso_de_instagram_la_sincronizacion_de_facebook_sigue(): void
    {
        Storage::fake('public');
        config(['services.facebook.page_id' => '123', 'services.facebook.page_token' => 'token']);
        AjusteSitio::fijar('seguidores_instagram', '11000'); // capturado a mano

        Http::fake(fn (Request $r) => match (true) {
            str_contains($r['fields'] ?? '', 'instagram') => Http::response(['error' => ['message' => '(#100) Missing permission instagram_basic']], 400),
            default => Http::response(['followers_count' => 81125, 'data' => []]),
        });

        $resumen = app(SincronizadorFacebook::class)->sincronizar();

        $this->assertSame([81125, null], [$resumen['seguidores'], $resumen['instagram']]);
        $this->assertSame('11000', AjusteSitio::valor('seguidores_instagram'));
        $this->assertNull(AjusteSitio::valor('facebook_error'));
    }

    public function test_el_panel_valida_seguidores_y_enlaces(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EditAjusteSitio::class, ['record' => 'seguidores_tiktok'])
            ->fillForm(['valor' => '@AndantesDelAsfalto'])
            ->call('save')
            ->assertHasFormErrors(['valor']);

        Livewire::test(EditAjusteSitio::class, ['record' => 'url_tiktok'])
            ->fillForm(['valor' => 'https://www.tiktok.com/@andantes'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditAjusteSitio::class, ['record' => 'seguidores_tiktok'])
            ->fillForm(['valor' => '5400'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['https://www.tiktok.com/@andantes', '5400'], [AjusteSitio::valor('url_tiktok'), AjusteSitio::valor('seguidores_tiktok')]);
    }
}
