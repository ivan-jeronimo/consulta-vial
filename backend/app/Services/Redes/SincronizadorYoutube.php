<?php

namespace App\Services\Redes;

use App\Models\AjusteSitio;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Suscriptores del canal de YouTube (url_youtube) con la YouTube Data API v3.
 * Un error no detiene el resto de la sincronización: queda en `youtube_error`.
 * YouTube redondea el número público (p. ej. 12,345 → 12,300).
 */
class SincronizadorYoutube
{
    public function configurado(): bool
    {
        return filled(config('services.youtube.key')) && filled(AjusteSitio::valor('url_youtube'));
    }

    public function sincronizar(): ?int
    {
        if (! $this->configurado()) {
            return null;
        }

        try {
            $suscriptores = $this->consultar((string) AjusteSitio::valor('url_youtube'));
        } catch (Throwable $e) {
            // Un error de conexión trae la URL completa: sin la clave.
            $mensaje = preg_replace('/key=[^&\s"]+/', 'key=***', $e->getMessage());
            AjusteSitio::fijar('youtube_error', now()->format('d/m/Y H:i').' — '.$mensaje);

            return null;
        }

        AjusteSitio::fijar('seguidores_youtube', (string) $suscriptores);
        AjusteSitio::fijar('youtube_error', null);

        return $suscriptores;
    }

    private function consultar(string $url): int
    {
        $respuesta = Http::timeout(20)->retry(2, 1000, throw: false)->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'statistics',
            'key' => config('services.youtube.key'),
            ...$this->canal($url),
        ]);

        if ($respuesta->failed()) {
            throw new RuntimeException('YouTube respondió: '.($respuesta->json('error.message') ?? 'HTTP '.$respuesta->status()));
        }

        $estadisticas = $respuesta->json('items.0.statistics');

        if ($estadisticas === null) {
            throw new RuntimeException("No se encontró el canal de {$url}");
        }
        if ($estadisticas['hiddenSubscriberCount'] ?? false) {
            throw new RuntimeException('El canal oculta su número de suscriptores.');
        }

        return (int) $estadisticas['subscriberCount'];
    }

    /** youtube.com/@andantes, @andantes o youtube.com/channel/UC… */
    private function canal(string $url): array
    {
        return match (true) {
            (bool) preg_match('~/channel/(UC[\w-]+)~', $url, $m) => ['id' => $m[1]],
            (bool) preg_match('~(?:^|/)(@[\w.\-]+)~', $url, $m) => ['forHandle' => $m[1]],
            default => throw new RuntimeException('Usa el enlace del canal con @ (youtube.com/@tucanal) o con /channel/.'),
        };
    }
}
