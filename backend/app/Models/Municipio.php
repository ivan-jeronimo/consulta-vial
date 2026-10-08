<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Municipio extends Model
{
    protected $fillable = ['estado_id', 'clave_inegi', 'nombre', 'slug'];

    protected static function booted(): void
    {
        static::saving(function (Municipio $municipio) {
            $municipio->slug = Str::slug($municipio->nombre);
        });
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoReglamento::class);
    }
}
