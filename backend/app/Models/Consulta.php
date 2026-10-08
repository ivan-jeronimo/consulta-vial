<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consulta extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'respondida' => 'boolean',
            'cubierto' => 'boolean',
            'fuentes' => 'array',
            'documentos' => 'array',
            'valorada_at' => 'datetime',
        ];
    }

    public function tema(): BelongsTo
    {
        return $this->belongsTo(TemaConsulta::class, 'tema_id');
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }
}
