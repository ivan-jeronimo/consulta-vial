<?php

use App\Enums\TipoDocumento;
use App\Models\DocumentoReglamento;
use App\Services\Facebook\SincronizadorFacebook;
use App\Services\Reto\GeneradorPreguntas;
use App\Services\Redes\SincronizadorYoutube;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sitio:sincronizar', function (SincronizadorFacebook $facebook, SincronizadorYoutube $youtube) {
    // YouTube primero: un error de Facebook no debe dejarlo sin actualizar.
    if ($youtube->configurado()) {
        $this->info('YouTube: '.($youtube->sincronizar() ?? 'error (ver Ajustes del sitio)').' suscriptores');
    }

    if (filled(config('services.facebook.page_token'))) {
        $resumen = $facebook->sincronizar();
        $this->info(sprintf(
            'Facebook: %s seguidores · Instagram: %s · videos: %d · publicaciones #dinamica nuevas: %d',
            $resumen['seguidores'] ?? '—', $resumen['instagram'] ?? 'sin vincular', $resumen['videos'], $resumen['publicaciones_nuevas'],
        ));
    }
})->purpose('Trae seguidores (Facebook, Instagram, YouTube), videos y publicaciones con #dinamica');

Artisan::command('reto:generar-preguntas {documento? : id; por omisión la guía de examen} {--capitulo=} {--lotes=3}', function (GeneradorPreguntas $generador) {
    $documento = $this->argument('documento')
        ? DocumentoReglamento::findOrFail($this->argument('documento'))
        : DocumentoReglamento::consultable()->where('tipo', TipoDocumento::Manual)->firstOrFail();

    $creadas = $generador->generar($documento, $this->option('capitulo'), (int) $this->option('lotes'));

    $this->info("{$creadas} preguntas nuevas en borrador de «{$documento->titulo}». Quedan "
        .$generador->pendientes($documento, $this->option('capitulo'))->count().' fragmentos sin usar.');
})->purpose('Genera preguntas del reto con el LLM (quedan como borrador para revisarlas en el panel)');

Schedule::command('sitio:sincronizar')
    ->everySixHours()
    ->withoutOverlapping()
    ->when(fn () => filled(config('services.facebook.page_token')) || filled(config('services.youtube.key')));
