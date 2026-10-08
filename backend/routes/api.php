<?php

use App\Http\Controllers\Admin\CatalogoController;
use App\Http\Controllers\Admin\ReglamentoController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\FuentesController;
use App\Http\Controllers\TerritorioController;
use App\Http\Middleware\AdminToken;
use App\Http\Middleware\ClienteFuentes;
use Illuminate\Support\Facades\Route;

// Endpoints públicos del bot. Cada consulta cuesta (embedding + LLM): se limita por IP.
Route::get('territorios', TerritorioController::class)->middleware('throttle:60,1');
Route::post('consulta', ConsultaController::class)->middleware('throttle:20,1');
Route::post('consultas/{consulta}/valoracion', [ConsultaController::class, 'valorar'])->middleware('throttle:30,1');

// Texto de la normativa para sistemas cliente (solo lectura, token por cliente).
Route::prefix('fuentes')->middleware([ClienteFuentes::class, 'throttle:120,1'])->group(function () {
    Route::get('documentos', [FuentesController::class, 'documentos']);
    Route::get('documentos/{documento}/fragmentos', [FuentesController::class, 'fragmentos'])->whereNumber('documento');
});

Route::prefix('admin')->middleware(AdminToken::class)->group(function () {
    Route::get('estados', [CatalogoController::class, 'estados']);
    Route::get('estados/{estado}/municipios', [CatalogoController::class, 'municipios']);
    Route::post('estados/{estado}/municipios', [CatalogoController::class, 'crearMunicipio']);

    Route::apiResource('reglamentos', ReglamentoController::class);
    Route::post('reglamentos/{reglamento}/reprocesar', [ReglamentoController::class, 'reprocesar']);
});
