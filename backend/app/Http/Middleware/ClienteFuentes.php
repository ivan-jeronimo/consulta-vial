<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API de fuentes: Authorization: Bearer {uno de FUENTES_API_TOKENS}. Solo lectura;
 * no da acceso a la API de administración.
 */
class ClienteFuentes
{
    public function handle(Request $request, Closure $next): Response
    {
        $recibido = (string) $request->bearerToken();
        $valido = $recibido !== '' && collect(config('rag.fuentes_tokens'))->contains(fn (string $t) => hash_equals($t, $recibido));

        abort_unless($valido, 401, 'No autorizado.');

        return $next($request);
    }
}
