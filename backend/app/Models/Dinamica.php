<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dinamica extends Model
{
    protected $fillable = [
        'facebook_post_id', 'titulo', 'descripcion', 'premio', 'imagen', 'url_publicacion',
        'inicia_el', 'termina_el', 'video_inicio_id', 'video_en_vivo_id', 'video_cierre_id', 'visible',
    ];

    /** Etapas en orden: relación => columna. */
    public const VIDEOS = ['videoInicio' => 'video_inicio_id', 'videoEnVivo' => 'video_en_vivo_id', 'videoCierre' => 'video_cierre_id'];

    protected function casts(): array
    {
        return ['inicia_el' => 'date', 'termina_el' => 'date', 'visible' => 'boolean'];
    }

    public function ganadores(): HasMany
    {
        return $this->hasMany(Ganador::class);
    }

    public function videoInicio(): BelongsTo
    {
        return $this->belongsTo(VideoDestacado::class, 'video_inicio_id');
    }

    /** El sorteo en vivo donde se elige al ganador. */
    public function videoEnVivo(): BelongsTo
    {
        return $this->belongsTo(VideoDestacado::class, 'video_en_vivo_id');
    }

    /** Anuncio de los ganadores / entrega del premio. */
    public function videoCierre(): BelongsTo
    {
        return $this->belongsTo(VideoDestacado::class, 'video_cierre_id');
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('visible', true);
    }

    /** proxima | activa | terminada, según las fechas (sin fechas cuenta como activa). */
    public function estatus(): string
    {
        $hoy = today();

        return match (true) {
            $this->inicia_el?->gt($hoy) ?? false => 'proxima',
            $this->termina_el?->lt($hoy) ?? false => 'terminada',
            default => 'activa',
        };
    }
}
