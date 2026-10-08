<?php

namespace App\Models;

use App\Enums\NivelJurisdiccion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReglamentoArticulo extends Model
{
    protected $table = 'reglamentos_articulos';

    protected $guarded = ['id'];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'nivel' => NivelJurisdiccion::class,
            'embedding' => AsVector::class,
            'metadata' => 'array',
        ];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoReglamento::class, 'documento_id');
    }

    /**
     * Normativa aplicable a un punto del territorio: lo federal, lo del estado
     * y lo del municipio, solo de documentos vigentes y ya procesados.
     */
    public function scopeAplicableA(Builder $query, ?int $estadoId, ?int $municipioId = null): void
    {
        $query
            ->where(function (Builder $q) use ($estadoId, $municipioId) {
                $q->where('nivel', NivelJurisdiccion::Federal);

                if ($estadoId) {
                    $q->orWhere(fn (Builder $q) => $q
                        ->where('nivel', NivelJurisdiccion::Estatal)
                        ->where('estado_id', $estadoId));
                }

                if ($municipioId) {
                    $q->orWhere(fn (Builder $q) => $q
                        ->where('nivel', NivelJurisdiccion::Municipal)
                        ->where('municipio_id', $municipioId));
                }
            })
            ->whereHas('documento', fn (Builder $q) => $q->consultable());
    }
}
