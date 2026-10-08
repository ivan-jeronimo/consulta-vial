<?php

namespace App\Http\Controllers;

use App\Enums\NivelJurisdiccion;
use App\Models\DocumentoReglamento;
use App\Models\Estado;
use App\Models\Municipio;
use Illuminate\Http\JsonResponse;

/**
 * Cobertura pública: estados y municipios que tienen normativa consultable,
 * para que el usuario solo elija lugares donde el bot puede responder.
 */
class TerritorioController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $documentos = DocumentoReglamento::consultable()->get(['nivel', 'estado_id', 'municipio_id']);

        $municipios = Municipio::query()
            ->whereIn('id', $documentos->where('nivel', NivelJurisdiccion::Municipal)->pluck('municipio_id'))
            ->orderBy('nombre')
            ->get(['id', 'estado_id', 'nombre'])
            ->groupBy('estado_id');

        $estados = Estado::query()
            ->whereIn('id', $documentos->pluck('estado_id')->filter()->unique())
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Estado $e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'tiene_reglamento_estatal' => $documentos->contains(fn ($d) => $d->nivel === NivelJurisdiccion::Estatal && $d->estado_id === $e->id),
                'municipios' => $municipios->get($e->id, collect())->map->only(['id', 'nombre'])->values(),
            ]);

        return response()->json([
            'hay_normativa_federal' => $documentos->contains('nivel', NivelJurisdiccion::Federal),
            'estados' => $estados,
        ])->setPublic()->setMaxAge(300);
    }
}
