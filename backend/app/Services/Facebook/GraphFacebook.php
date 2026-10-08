<?php

namespace App\Services\Facebook;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cliente mínimo de la Graph API con el token de la página. Los mensajes de
 * error nunca llevan el token (terminan en el panel y en los logs).
 */
class GraphFacebook
{
    public function __construct(
        private readonly ?string $token = null,
        private readonly string $version = 'v26.0',
    ) {}

    public static function desdeConfig(): self
    {
        return new self(config('services.facebook.page_token'), config('services.facebook.graph_version'));
    }

    public function configurado(): bool
    {
        return filled($this->token);
    }

    public function get(string $ruta, array $query = []): array
    {
        return $this->enviar(fn (PendingRequest $http) => $http->get(
            "https://graph.facebook.com/{$this->version}/".ltrim($ruta, '/'),
            $query + ['access_token' => $this->token],
        ));
    }

    /** Recorre todas las páginas de resultados de un edge (/videos, /posts…). */
    public function paginar(string $ruta, array $query = []): Generator
    {
        $respuesta = $this->get($ruta, $query);

        while (true) {
            yield from $respuesta['data'] ?? [];

            // `next` ya trae el token y el cursor.
            $siguiente = $respuesta['paging']['next'] ?? null;
            if (! $siguiente || empty($respuesta['data'])) {
                return;
            }
            $respuesta = $this->enviar(fn (PendingRequest $http) => $http->get($siguiente));
        }
    }

    /** Guarda una imagen del CDN de Facebook en el disco public y devuelve su ruta. */
    public function descargar(string $url, string $ruta): string
    {
        $respuesta = $this->enviar(fn (PendingRequest $http) => $http->get($url), json: false);
        Storage::disk('public')->put($ruta, $respuesta->body());

        return $ruta;
    }

    /** @param  callable(PendingRequest): Response  $peticion */
    private function enviar(callable $peticion, bool $json = true): mixed
    {
        try {
            $respuesta = $peticion(Http::timeout(60)->connectTimeout(10)->retry(2, 2000, throw: false));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Sin conexión con Facebook: '.$this->limpiar($e->getMessage()));
        }

        if ($respuesta->failed()) {
            $error = $respuesta->json('error.message') ?? 'HTTP '.$respuesta->status();
            throw new RuntimeException('Facebook respondió: '.$this->limpiar($error), $respuesta->status());
        }

        return $json ? $respuesta->json() : $respuesta;
    }

    private function limpiar(string $mensaje): string
    {
        return preg_replace('/access_token=[^&\s"]+/', 'access_token=***', $mensaje);
    }
}
