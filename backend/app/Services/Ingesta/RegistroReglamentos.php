<?php

namespace App\Services\Ingesta;

use App\Enums\EstatusProcesamiento;
use App\Enums\NivelJurisdiccion;
use App\Jobs\ProcesarReglamentoJob;
use App\Models\DocumentoReglamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * Operaciones administrativas sobre reglamentos, compartidas por la API y el
 * panel Filament: alta (con despacho del Job), edición y re-proceso.
 */
class RegistroReglamentos
{
    public const MIME_POR_EXTENSION = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
    ];

    /** Guarda texto pegado como archivo .txt y devuelve su ruta en el disco de ingesta. */
    public function guardarTexto(string $texto): string
    {
        $path = config('rag.ingesta.directorio').'/'.Str::uuid().'.txt';
        $this->disco()->put($path, $texto);

        return $path;
    }

    /**
     * Crea el documento sobre un archivo ya guardado en el disco de ingesta y
     * encola su procesamiento. Si el registro falla, el archivo se elimina.
     */
    public function registrar(array $metadatos, string $path, ?string $nombreOriginal = null): DocumentoReglamento
    {
        try {
            $documento = DocumentoReglamento::create([
                ...$this->normalizarTerritorio($metadatos),
                'archivo_path' => $path,
                'archivo_nombre_original' => $nombreOriginal,
                'archivo_mime' => $this->mimeDe($path),
                'archivo_sha256' => hash_file('sha256', $this->disco()->path($path)),
                'estatus_procesamiento' => EstatusProcesamiento::Pendiente,
            ]);
        } catch (\Throwable $e) {
            $this->disco()->delete($path);

            throw $e;
        }

        // Si reemplaza_a_id viene informado, el Job deroga el documento anterior
        // solo cuando esta reforma queda procesada.
        ProcesarReglamentoJob::dispatch($documento);

        return $documento;
    }

    /** Actualiza metadatos; los vectores heredan las llaves territoriales. */
    public function actualizar(DocumentoReglamento $documento, array $datos): DocumentoReglamento
    {
        DB::transaction(function () use ($documento, $datos) {
            $documento->update($this->normalizarTerritorio($datos, $documento));

            if ($documento->wasChanged(['nivel', 'estado_id', 'municipio_id'])) {
                $documento->articulos()->update([
                    'nivel' => $documento->nivel->value,
                    'estado_id' => $documento->estado_id,
                    'municipio_id' => $documento->municipio_id,
                ]);
            }
        });

        // El tipo decide cómo se divide (por artículos o por secciones): hay que rehacerlo.
        if ($documento->wasChanged('tipo') && $this->puedeReprocesar($documento)) {
            $this->reprocesar($documento);
        }

        return $documento;
    }

    public function puedeReprocesar(DocumentoReglamento $documento): bool
    {
        return ! $documento->estatus_procesamiento->enCurso();
    }

    public function reprocesar(DocumentoReglamento $documento): void
    {
        if (! $this->puedeReprocesar($documento)) {
            throw new LogicException('El documento ya está en cola o procesándose.');
        }

        $documento->update(['estatus_procesamiento' => EstatusProcesamiento::Pendiente, 'error_mensaje' => null]);
        ProcesarReglamentoJob::dispatch($documento);
    }

    public function mimeDe(string $path): string
    {
        return self::MIME_POR_EXTENSION[strtolower(pathinfo($path, PATHINFO_EXTENSION))]
            ?? $this->disco()->mimeType($path);
    }

    /** Federal sin estado ni municipio; estatal sin municipio. */
    private function normalizarTerritorio(array $datos, ?DocumentoReglamento $actual = null): array
    {
        $nivel = $datos['nivel'] ?? $actual?->nivel;
        $nivel = $nivel instanceof NivelJurisdiccion ? $nivel : NivelJurisdiccion::tryFrom((string) $nivel);

        if ($nivel === NivelJurisdiccion::Federal) {
            $datos['estado_id'] = null;
        }

        if ($nivel !== NivelJurisdiccion::Municipal && $nivel !== null) {
            $datos['municipio_id'] = null;
        }

        return $datos;
    }

    private function disco()
    {
        return Storage::disk(config('rag.ingesta.disk'));
    }
}
