<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación provisional del panel: Authorization: Bearer {ADMIN_API_TOKEN}.
 * Reemplazar por Sanctum + roles cuando exista el login del panel.
 */
class AdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('rag.admin_token');

        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401, 'No autorizado.');

        return $next($request);
    }
}
