<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemaConsulta extends Model
{
    protected $table = 'temas_consulta';

    protected $fillable = ['etiqueta', 'embedding'];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return ['embedding' => AsVector::class];
    }

    public function consultas(): HasMany
    {
        return $this->hasMany(Consulta::class, 'tema_id');
    }
}
