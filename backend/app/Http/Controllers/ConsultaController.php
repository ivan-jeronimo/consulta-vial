<?php

namespace App\Http\Controllers;

use App\Enums\NivelJurisdiccion;
use App\Models\Estado;
use App\Models\Municipio;
use App\Models\ReglamentoArticulo;
use App\Services\Consulta\BuscadorArticulos;
use App\Services\Consulta\GeneradorRespuesta;
use App\Services\Consulta\LimiteConsultas;
use App\Services\Metricas\RegistroConsultas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConsultaController extends Controller
{
    public function __invoke(Request $request, BuscadorArticulos $buscador, GeneradorRespuesta $generador, RegistroConsultas $registro, LimiteConsultas $limite): JsonResponse
    {
        $inicio = microtime(true);

        $datos = $request->validate([
            'pregunta' => ['required', 'string', 'min:5', 'max:1000'],
            'estado' => ['nullable', 'string', 'max:80'],
            'municipio' => ['nullable', 'string', 'max:150'],
            'estado_id' => ['nullable', 'integer', 'exists:estados,id'],
            'municipio_id' => ['nullable', 'integer', 'exists:municipios,id'],
            // Identificador aleatorio del navegador, solo para contar usuarios únicos.
            'visitante' => ['nullable', 'uuid'],
            // Sitio que incrusta el chat (p. ej. "andantes"), para las métricas.
            'origen' => ['nullable', 'string', 'max:40', 'alpha_dash'],
        ]);

        $limite->registrar($datos['visitante'] ?? null, (string) $request->ip());

        [$estado, $municipio] = $this->resolverTerritorio($datos);

        $articulos = $buscador->buscar($datos['pregunta'], $estado?->id, $municipio?->id);
        $ubicacion = collect([$municipio?->nombre, $estado?->nombre])->filter()->implode(', ') ?: 'México (solo normativa federal)';

        $respuesta = null;
        $aviso = null;

        if ($articulos->isEmpty()) {
            $aviso = 'No se encontraron artículos aplicables para esta ubicación.';
        } elseif ($generador->disponible()) {
            try {
                $respuesta = $generador->responder($datos['pregunta'], $ubicacion, $articulos);
            } catch (\Throwable $e) {
                // Los artículos ya se encontraron: mejor mostrarlos que fallar.
                report($e);
                $aviso = 'No pude redactar la respuesta en este momento; estos son los artículos del reglamento relacionados con tu pregunta.';
            }
        } else {
            $aviso = 'LLM no configurado (LLM_PROVIDER / ANTHROPIC_API_KEY): se devuelven solo los artículos encontrados.';
        }

        // Con respuesta redactada, el fundamento son solo los apartados que el
        // modelo declaró usar (si no lo declaró, se muestran todos los encontrados).
        $fuentes = $respuesta?->fuentesUsadas !== null
            // toBase(): en una colección Eloquent only() filtra por id del modelo, no por posición.
            ? $articulos->toBase()->values()->only($respuesta->fuentesUsadas)->values()
            : $articulos;

        $sinCobertura = $articulos->isEmpty() || $respuesta?->cubierto === false;
        $consultaId = (string) Str::uuid();

        // Métricas anónimas: se guardan después de enviar la respuesta, para no
        // hacer esperar al usuario por el cálculo del tema.
        defer(fn () => $registro->registrar([
            'id' => $consultaId,
            'visitante' => $datos['visitante'] ?? null,
            'origen' => $datos['origen'] ?? null,
            'pregunta' => $datos['pregunta'],
            'estado_id' => $estado?->id,
            'municipio_id' => $municipio?->id,
            'respondida' => $respuesta !== null,
            'cubierto' => $articulos->isEmpty() ? false : $respuesta?->cubierto,
            'modelo' => $respuesta?->modelo,
            'fuentes' => $fuentes->pluck('id')->values()->all(),
            'documentos' => $fuentes->pluck('documento_id')->unique()->values()->all(),
            'latencia_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ]));

        return response()->json([
            'consulta_id' => $consultaId,
            'pregunta' => $datos['pregunta'],
            'ubicacion' => $ubicacion,
            'respuesta' => $respuesta?->texto,
            'aviso' => $aviso,
            'modelo' => $respuesta?->modelo,
            // Muchas reglas de circulación (semáforos, vuelta continua…) están en
            // reglamentos municipales: si el estatal no responde, se ofrecen.
            'sugerir_municipios' => $sinCobertura && $estado && ! $municipio
                ? $this->municipiosConReglamento($estado)
                : [],
            'fuentes' => $fuentes->map(fn (ReglamentoArticulo $a) => [
                'documento_id' => $a->documento_id,
                'reglamento' => $a->documento->titulo,
                'tipo' => $a->documento->tipo,
                'nivel' => $a->documento->nivel,
                'anio' => $a->documento->anio,
                'capitulo' => $a->capitulo,
                'seccion' => $a->seccion,
                'articulo' => $a->articulo,
                'fraccion' => $a->fraccion,
                'inciso' => $a->inciso,
                'orden' => $a->orden,
                'relevancia' => $a->relevancia,
                'contexto' => $a->contexto,
                'texto' => $a->contenido,
            ]),
        ]);
    }

    /** 👍 / 👎 sobre una respuesta, hasta 24 horas después de recibirla. */
    public function valorar(Request $request, string $consulta, RegistroConsultas $registro): JsonResponse
    {
        $datos = $request->validate(['util' => ['required', 'boolean']]);

        abort_unless(Str::isUuid($consulta) && $registro->valorar($consulta, (bool) $datos['util']), 404);

        return response()->json(['ok' => true]);
    }

    /** @return list<array{id: int, nombre: string}> */
    private function municipiosConReglamento(Estado $estado): array
    {
        return Municipio::query()
            ->where('estado_id', $estado->id)
            ->whereHas('documentos', fn ($q) => $q->consultable()->where('nivel', NivelJurisdiccion::Municipal))
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Municipio $m) => ['id' => $m->id, 'nombre' => $m->nombre])
            ->all();
    }

    /** @return array{0: ?Estado, 1: ?Municipio} */
    private function resolverTerritorio(array $datos): array
    {
        $municipio = isset($datos['municipio_id']) ? Municipio::find($datos['municipio_id']) : null;
        $estado = $municipio?->estado
            ?? (isset($datos['estado_id']) ? Estado::find($datos['estado_id']) : null)
            ?? (filled($datos['estado'] ?? null) ? $this->estadoPorNombre($datos['estado']) : null);

        if (! $municipio && filled($datos['municipio'] ?? null)) {
            $candidatos = Municipio::with('estado')
                ->where('slug', Str::slug($datos['municipio']))
                ->when($estado, fn ($q) => $q->where('estado_id', $estado->id))
                ->get();

            if ($candidatos->isEmpty()) {
                throw ValidationException::withMessages(['municipio' => "No hay un municipio registrado llamado \"{$datos['municipio']}\"".($estado ? " en {$estado->nombre}." : '.')]);
            }

            if ($candidatos->count() > 1) {
                throw ValidationException::withMessages(['estado' => 'Hay varios municipios con ese nombre; indica también el estado.']);
            }

            $municipio = $candidatos->first();
            $estado = $municipio->estado;
        }

        return [$estado, $municipio];
    }

    private function estadoPorNombre(string $nombre): Estado
    {
        $slug = Str::slug($nombre);

        // "Oaxaca", "Veracruz" o "Michoacán" deben encontrar el nombre oficial completo.
        return Estado::where('slug', $slug)->first()
            ?? Estado::where('slug', 'like', "{$slug}-%")->first()
            ?? throw ValidationException::withMessages(['estado' => "Estado \"{$nombre}\" no reconocido."]);
    }
}
