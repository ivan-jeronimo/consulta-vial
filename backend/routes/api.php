<?php

use App\Http\Controllers\Admin\CatalogoController;
use App\Http\Controllers\Admin\ReglamentoController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\SitioController;
use App\Http\Controllers\TerritorioController;
use App\Http\Middleware\AdminToken;
use Illuminate\Support\Facades\Route;

// Endpoints públicos del bot. Cada consulta cuesta (embedding + LLM): se limita por IP.
Route::get('territorios', TerritorioController::class)->middleware('throttle:60,1');
Route::post('consulta', ConsultaController::class)->middleware('throttle:20,1');
Route::post('consultas/{consulta}/valoracion', [ConsultaController::class, 'valorar'])->middleware('throttle:30,1');

// Sitio de la comunidad (Andantes del Asfalto).
Route::get('sitio', SitioController::class)->middleware('throttle:60,1');

Route::prefix('admin')->middleware(AdminToken::class)->group(function () {
    Route::get('estados', [CatalogoController::class, 'estados']);
    Route::get('estados/{estado}/municipios', [CatalogoController::class, 'municipios']);
    Route::post('estados/{estado}/municipios', [CatalogoController::class, 'crearMunicipio']);

    Route::apiResource('reglamentos', ReglamentoController::class);
    Route::post('reglamentos/{reglamento}/reprocesar', [ReglamentoController::class, 'reprocesar']);
});
