<?php

namespace App\Services\Consulta;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

/**
 * Límites diarios del chat (config rag.limites): por persona, por conexión y
 * en total. Los contadores viven en la caché (Redis) hasta medianoche.
 */
class LimiteConsultas
{
    /** Cuenta la consulta, o responde 429 con un mensaje para mostrar si ya no hay cupo. */
    public function registrar(?string $visitante, string $ip): void
    {
        $limites = config('rag.limites');
        $persona = (int) $limites['por_persona_al_dia'];
        $mensajePersona = "Llegaste al límite de {$persona} preguntas por hoy. Vuelve mañana para seguir preguntando.";

        $contadores = [
            ['total', (int) $limites['total_al_dia'], 'El asistente ya respondió todas las preguntas que puede por hoy. Vuelve mañana.'],
            ["ip:{$ip}", (int) $limites['por_ip_al_dia'], $mensajePersona],
            ['persona:'.($visitante ?? "ip:{$ip}"), $persona, $mensajePersona],
        ];

        foreach ($contadores as [$clave, $limite, $mensaje]) {
            if ($limite > 0 && $this->usadas($clave) >= $limite) {
                throw new HttpResponseException(response()->json(['message' => $mensaje, 'limite_diario' => true], 429));
            }
        }

        foreach ($contadores as [$clave]) {
            $clave = $this->clave($clave);
            Cache::add($clave, 0, now()->endOfDay());
            Cache::increment($clave);
        }
    }

    public function usadas(string $clave): int
    {
        return (int) Cache::get($this->clave($clave), 0);
    }

    private function clave(string $clave): string
    {
        return 'consultas:'.today()->toDateString().':'.$clave;
    }
}
