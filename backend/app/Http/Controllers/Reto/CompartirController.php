<?php

namespace App\Http\Controllers\Reto;

use App\Http\Controllers\Controller;
use App\Models\Partida;
use App\Services\Reto\TarjetaResultado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Enlace para compartir un resultado (/r/{partida}). Los robots de las redes
 * leen la vista previa (Open Graph); las personas van directo al reto.
 */
class CompartirController extends Controller
{
    /** Robots que generan las vistas previas de enlaces. */
    private const ROBOTS = '/facebookexternalhit|facebookcatalog|WhatsApp|Twitterbot|TelegramBot|Slackbot|LinkedInBot|Discordbot|Pinterest|Googlebot|bingbot/i';

    public function pagina(Request $request, Partida $partida): Response|RedirectResponse
    {
        abort_unless($partida->terminada_at, 404);

        $sitio = rtrim((string) config('services.facebook.sitio_url'), '/');

        if (! preg_match(self::ROBOTS, (string) $request->userAgent())) {
            return redirect()->away("{$sitio}/?desde=compartido#reto");
        }

        return response()->view('compartir', [
            'titulo' => TarjetaResultado::nombreCorto($partida->jugador->nombre).' hizo '.number_format($partida->puntos).' puntos en El reto del asfalto',
            'descripcion' => "{$partida->aciertos} de {$partida->total()} correctas en el simulador del examen de manejo de Oaxaca. ¿Me superas?",
            'imagen' => "{$sitio}/r/{$partida->id}/imagen.png",
            'url' => "{$sitio}/r/{$partida->id}",
            'destino' => "{$sitio}/#reto",
        ]);
    }

    public function imagen(Partida $partida, TarjetaResultado $tarjeta): BinaryFileResponse
    {
        abort_unless($partida->terminada_at, 404);

        // Una partida terminada no cambia: la imagen se puede guardar en caché para siempre.
        return response()->file(Storage::disk('public')->path($tarjeta->ruta($partida)), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
