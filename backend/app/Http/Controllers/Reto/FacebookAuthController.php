<?php

namespace App\Http\Controllers\Reto;

use App\Http\Controllers\Controller;
use App\Services\Reto\LoginFacebook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FacebookAuthController extends Controller
{
    public function __construct(private readonly LoginFacebook $login) {}

    public function redirigir(Request $request): RedirectResponse
    {
        abort_unless($this->login->disponible(), 503, 'El inicio de sesión con Facebook no está configurado.');

        // `state` evita que alguien inicie sesión a nombre de otro con un enlace preparado.
        $state = Str::random(40);
        $request->session()->put('facebook_state', $state);

        return redirect()->away($this->login->urlDialogo($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('facebook_state');

        if ($request->filled('error')) {
            return $this->volver('cancelado'); // la persona no aceptó en Facebook
        }
        if (! $state || ! hash_equals($state, (string) $request->query('state')) || ! $request->filled('code')) {
            // Sin state en la sesión casi siempre es que se abrió el sitio en otra dirección
            // (127.0.0.1, IP de la red) que la de SITIO_URL: el navegador no manda la cookie.
            Log::warning('Inicio de sesión con Facebook rechazado', [
                'motivo' => ! $state ? 'sin state en la sesión' : ($request->filled('code') ? 'state distinto' : 'sin code'),
                'host' => $request->getHttpHost(),
                'sitio_url' => config('services.facebook.sitio_url'),
            ]);

            return $this->volver('error');
        }

        try {
            $jugador = $this->login->completar((string) $request->query('code'));
        } catch (Throwable $e) {
            Log::warning('Falló el inicio de sesión con Facebook', ['error' => $e->getMessage()]);

            return $this->volver('error');
        }

        Auth::guard('jugador')->login($jugador, remember: true);
        $request->session()->regenerate();

        return $this->volver();
    }

    public function salir(Request $request): JsonResponse
    {
        Auth::guard('jugador')->logout();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    private function volver(?string $error = null): RedirectResponse
    {
        $sitio = rtrim((string) config('services.facebook.sitio_url'), '/');

        return redirect()->away($sitio.'/'.($error ? "?login={$error}" : '').'#reto');
    }
}
