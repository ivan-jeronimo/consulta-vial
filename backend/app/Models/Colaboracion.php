<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Colaboracion extends Model
{
    protected $table = 'colaboraciones';

    protected $fillable = ['nombre', 'descripcion', 'logo', 'url', 'fecha', 'orden', 'visible'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'visible' => 'boolean'];
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('visible', true);
    }
}
