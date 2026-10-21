<?php

namespace App\Http\Controllers;

use App\Models\DocumentoReglamento;
use App\Models\ReglamentoArticulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Texto de la normativa para otros sistemas (p. ej. el reto de Andantes del
 * Asfalto genera preguntas con él). Solo documentos vigentes y procesados.
 */
class FuentesController extends Controller
{
    public function documentos(): JsonResponse
    {
        $documentos = DocumentoReglamento::consultable()->orderBy('titulo')->get(['id', 'titulo', 'emisor', 'tipo', 'nivel', 'anio', 'url_publica', 'publicado_en']);

        // Capítulos en el orden del documento, para filtrar al generar.
        $capitulos = ReglamentoArticulo::query()
            ->whereIn('documento_id', $documentos->pluck('id'))
            ->whereNotNull('capitulo')
            ->selectRaw('documento_id, capitulo, min(orden) as primero')
            ->groupBy('documento_id', 'capitulo')
            ->orderBy('primero')
            ->get()
            ->groupBy('documento_id');

        return response()->json([
            'data' => $documentos->map(fn (DocumentoReglamento $d) => [
                'id' => $d->id,
                'titulo' => $d->titulo,
                'emisor' => $d->emisor,
                'tipo' => $d->tipo->value,
                'nivel' => $d->nivel->value,
                'anio' => $d->anio,
                'url' => $d->url_publica,
                'publicado_en' => $d->publicado_en,
                'capitulos' => $capitulos->get($d->id, collect())->pluck('capitulo')->values(),
            ]),
        ]);
    }

    public function fragmentos(Request $request, DocumentoReglamento $documento): JsonResponse
    {
        abort_unless(DocumentoReglamento::consultable()->whereKey($documento->id)->exists(), 404);

        $fragmentos = $documento->articulos()
            ->when($request->query('capitulo'), fn ($q, $capitulo) => $q->where('capitulo', $capitulo))
            ->orderBy('orden')
            ->get(['id', 'orden', 'capitulo', 'seccion', 'articulo', 'fraccion', 'inciso', 'contenido']);

        return response()->json(['data' => $fragmentos]);
    }
}
