<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estado extends Model
{
    protected $fillable = ['clave_inegi', 'nombre', 'slug'];

    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class);
    }
}
