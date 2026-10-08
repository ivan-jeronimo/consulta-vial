<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GuardarReglamentoRequest;
use App\Models\DocumentoReglamento;
use App\Services\Ingesta\RegistroReglamentos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReglamentoController extends Controller
{
    public function __construct(private RegistroReglamentos $registro) {}

    public function index(Request $request)
    {
        return DocumentoReglamento::query()
            ->with(['estado:id,nombre', 'municipio:id,nombre'])
            ->when($request->query('nivel'), fn ($q, $v) => $q->where('nivel', $v))
            ->when($request->query('estado_id'), fn ($q, $v) => $q->where('estado_id', $v))
            ->when($request->query('municipio_id'), fn ($q, $v) => $q->where('municipio_id', $v))
            ->when($request->query('estatus_vigencia'), fn ($q, $v) => $q->where('estatus_vigencia', $v))
            ->when($request->query('estatus_procesamiento'), fn ($q, $v) => $q->where('estatus_procesamiento', $v))
            ->latest()
            ->paginate(25);
    }

    public function store(GuardarReglamentoRequest $request): JsonResponse
    {
        if ($archivo = $request->file('archivo')) {
            // Se conserva la extensión original: de ella sale el MIME canónico
            // (libmagic puede reportar un DOCX como application/zip).
            $path = $archivo->storeAs(
                config('rag.ingesta.directorio'),
                Str::uuid().'.'.strtolower($archivo->getClientOriginalExtension()),
                config('rag.ingesta.disk'),
            );
            $nombreOriginal = $archivo->getClientOriginalName();
        } else {
            $path = $this->registro->guardarTexto((string) $request->string('texto'));
            $nombreOriginal = null;
        }

        $documento = $this->registro->registrar($request->safe()->except(['archivo', 'texto']), $path, $nombreOriginal);

        return response()->json($documento->load('estado', 'municipio'), 202);
    }

    public function show(DocumentoReglamento $reglamento)
    {
        return $reglamento->load('estado', 'municipio', 'reemplazaA:id,titulo,anio');
    }

    /** Metadatos y vigencia. Derogar surte efecto inmediato en las consultas del bot. */
    public function update(GuardarReglamentoRequest $request, DocumentoReglamento $reglamento)
    {
        $this->registro->actualizar($reglamento, $request->validated());

        return $reglamento->fresh(['estado', 'municipio']);
    }

    public function reprocesar(DocumentoReglamento $reglamento): JsonResponse
    {
        if (! $this->registro->puedeReprocesar($reglamento)) {
            return response()->json(['message' => 'El documento ya está en cola o procesándose.'], 409);
        }

        $this->registro->reprocesar($reglamento);

        return response()->json($reglamento, 202);
    }

    /** Borra el documento, sus vectores (FK en cascada) y el archivo fuente. */
    public function destroy(DocumentoReglamento $reglamento)
    {
        $reglamento->delete();

        return response()->noContent();
    }
}
