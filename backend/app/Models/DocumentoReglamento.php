<?php

namespace App\Models;

use App\Enums\EstatusProcesamiento;
use App\Enums\EstatusVigencia;
use App\Enums\NivelJurisdiccion;
use App\Enums\TipoDocumento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class DocumentoReglamento extends Model
{
    protected $table = 'documentos_reglamentos';

    protected $fillable = [
        'titulo', 'tipo', 'nivel', 'estado_id', 'municipio_id',
        'anio', 'fecha_publicacion', 'estatus_vigencia', 'reemplaza_a_id',
        'archivo_path', 'archivo_nombre_original', 'archivo_mime', 'archivo_sha256',
        'estatus_procesamiento', 'chunks_count', 'embedding_modelo', 'error_mensaje', 'procesado_at',
        'creado_por',
    ];

    protected $hidden = ['archivo_path'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumento::class,
            'nivel' => NivelJurisdiccion::class,
            'estatus_vigencia' => EstatusVigencia::class,
            'estatus_procesamiento' => EstatusProcesamiento::class,
            'fecha_publicacion' => 'date',
            'procesado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Los artículos se borran en cascada por FK; el archivo fuente se limpia aquí.
        static::deleted(function (DocumentoReglamento $documento) {
            Storage::disk(config('rag.ingesta.disk'))->delete($documento->archivo_path);
        });
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplaza_a_id');
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(ReglamentoArticulo::class, 'documento_id');
    }

    /** Documentos que el bot puede consultar. */
    public function scopeConsultable(Builder $query): void
    {
        $query->where('estatus_vigencia', EstatusVigencia::Vigente)
            ->where('estatus_procesamiento', EstatusProcesamiento::Completado);
    }
}
