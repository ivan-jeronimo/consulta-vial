<?php

namespace App\Services\Reto;

use App\Models\Jugador;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Inicio de sesión con Facebook (OAuth del lado del servidor) con la app
 * pública de login (FACEBOOK_APP_ID/SECRET). Solo pide public_profile y email.
 */
class LoginFacebook
{
    public function disponible(): bool
    {
        return filled(config('services.facebook.app_id')) && filled(config('services.facebook.app_secret'));
    }

    public function urlDialogo(string $state): string
    {
        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.facebook.app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => 'public_profile,email',
            'response_type' => 'code',
        ]);
    }

    /** Cambia el código de Facebook por los datos de la persona y crea o actualiza su jugador. */
    public function completar(string $codigo): Jugador
    {
        $token = $this->get('oauth/access_token', [
            'client_id' => config('services.facebook.app_id'),
            'client_secret' => config('services.facebook.app_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $codigo,
        ])['access_token'] ?? throw new RuntimeException('Facebook no devolvió token.');

        $persona = $this->get('me', [
            'fields' => 'id,name,email,picture.width(200).height(200){url,is_silhouette}',
            'access_token' => $token,
            // Prueba de que la llamada viene del servidor de la app (recomendado por Meta).
            'appsecret_proof' => hash_hmac('sha256', $token, (string) config('services.facebook.app_secret')),
        ]);

        $jugador = Jugador::firstOrNew(['facebook_id' => $persona['id']]);
        $jugador->fill([
            'nombre' => Str::limit($persona['name'] ?? 'Jugador', 150, ''),
            'email' => $persona['email'] ?? $jugador->email,
            'ultimo_acceso_at' => now(),
        ])->save();

        $foto = $persona['picture']['data'] ?? null;
        if ($foto && ! ($foto['is_silhouette'] ?? false) && isset($foto['url'])) {
            $this->guardarFoto($jugador, $foto['url']);
        }

        return $jugador;
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('services.facebook.sitio_url'), '/').'/auth/facebook/callback';
    }

    private function guardarFoto(Jugador $jugador, string $url): void
    {
        // El enlace del CDN de Facebook caduca: se guarda una copia.
        try {
            $respuesta = Http::timeout(15)->get($url)->throw();
            $ruta = "sitio/jugadores/{$jugador->id}.jpg";
            Storage::disk('public')->put($ruta, $respuesta->body());
            $jugador->update(['foto' => $ruta]);
        } catch (Throwable $e) {
            Log::warning('No se pudo guardar la foto del jugador', ['jugador' => $jugador->id, 'error' => $e->getMessage()]);
        }
    }

    private function get(string $ruta, array $query): array
    {
        $respuesta = Http::timeout(20)->get('https://graph.facebook.com/'.$this->version().'/'.$ruta, $query);

        if ($respuesta->failed()) {
            throw new RuntimeException('Facebook respondió: '.($respuesta->json('error.message') ?? 'HTTP '.$respuesta->status()));
        }

        return $respuesta->json();
    }

    private function version(): string
    {
        return (string) config('services.facebook.graph_version');
    }
}
