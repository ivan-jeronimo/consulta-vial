<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VideoDestacado extends Model
{
    protected $table = 'videos_destacados';

    protected $fillable = ['facebook_id', 'tipo', 'titulo', 'url', 'miniatura', 'vistas', 'publicado_el', 'fijado', 'visible'];

    protected function casts(): array
    {
        return ['publicado_el' => 'date', 'fijado' => 'boolean', 'visible' => 'boolean'];
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('visible', true);
    }

    /** Fijados primero; después, los más vistos. */
    public function scopeOrdenSitio(Builder $query): void
    {
        $query->orderByDesc('fijado')->orderByRaw('vistas desc nulls last')->orderByDesc('publicado_el');
    }
}
