<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RespuestaReto extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'respuestas_reto';

    protected $fillable = ['partida_id', 'pregunta_id', 'opcion', 'correcta', 'ms', 'puntos'];

    protected function casts(): array
    {
        return ['correcta' => 'boolean'];
    }

    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(PreguntaReto::class, 'pregunta_id');
    }
}
