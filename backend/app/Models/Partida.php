<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partida extends Model
{
    use HasUuids;

    // Milisegundos: mostrada_at mide cuánto tarda cada respuesta.
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['jugador_id', 'preguntas', 'actual', 'mostrada_at', 'puntos', 'aciertos', 'terminada_at'];

    protected function casts(): array
    {
        return ['preguntas' => 'array', 'mostrada_at' => 'datetime', 'terminada_at' => 'datetime'];
    }

    public function jugador(): BelongsTo
    {
        return $this->belongsTo(Jugador::class);
    }

    public function respuestas(): HasMany
    {
        return $this->hasMany(RespuestaReto::class);
    }

    public function scopeTerminada(Builder $query): void
    {
        $query->whereNotNull('terminada_at');
    }

    public function total(): int
    {
        return count($this->preguntas);
    }
}
