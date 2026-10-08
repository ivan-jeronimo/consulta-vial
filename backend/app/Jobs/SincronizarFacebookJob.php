<?php

namespace App\Jobs;

use App\Services\Facebook\SincronizadorFacebook;
use App\Services\Redes\SincronizadorYoutube;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Para el botón "Sincronizar ahora" del panel (el programado corre `sitio:sincronizar`). */
class SincronizarFacebookJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function handle(SincronizadorFacebook $facebook, SincronizadorYoutube $youtube): void
    {
        // YouTube primero: un error de Facebook no debe dejarlo sin actualizar.
        $youtube->sincronizar();

        if (filled(config('services.facebook.page_token'))) {
            $facebook->sincronizar();
        }
    }
}
