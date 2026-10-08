<?php

use App\Http\Controllers\Reto\CompartirController;
use App\Http\Controllers\Reto\FacebookAuthController;
use App\Http\Controllers\Reto\RetoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Reto del sitio de la comunidad. Van aquí (no en api.php) porque usan la
// sesión: el sitio las llama por el mismo origen (proxy de Vite / nginx).
Route::get('auth/facebook', [FacebookAuthController::class, 'redirigir'])->middleware('throttle:20,1');
Route::get('auth/facebook/callback', [FacebookAuthController::class, 'callback'])->middleware('throttle:20,1');

// Resultado compartido en redes: vista previa para los robots, el reto para las personas.
Route::get('r/{partida}', [CompartirController::class, 'pagina'])->whereUuid('partida')->middleware('throttle:120,1');
Route::get('r/{partida}/imagen.png', [CompartirController::class, 'imagen'])->whereUuid('partida')->middleware('throttle:120,1');

Route::prefix('api/reto')->middleware('throttle:120,1')->group(function () {
    Route::get('yo', [RetoController::class, 'yo']);
    Route::get('ranking', [RetoController::class, 'ranking']);
    Route::post('salir', [FacebookAuthController::class, 'salir']);

    Route::middleware('auth:jugador')->group(function () {
        Route::post('preferencias', [RetoController::class, 'preferencias']);
        Route::post('partidas', [RetoController::class, 'iniciar'])->middleware('throttle:30,1');
        Route::get('partidas/{partida}', [RetoController::class, 'pregunta']);
        Route::post('partidas/{partida}/respuestas', [RetoController::class, 'responder']);
        Route::post('borrar-cuenta', [RetoController::class, 'borrarCuenta']);
    });
});
