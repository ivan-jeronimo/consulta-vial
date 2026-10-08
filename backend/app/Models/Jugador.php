<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;

/** Persona que juega el reto; entra con Facebook (guard "jugador"). */
class Jugador extends Authenticatable
{
    protected $table = 'jugadores';

    protected $fillable = ['facebook_id', 'nombre', 'email', 'foto', 'publico', 'ultimo_acceso_at'];

    protected $hidden = ['remember_token', 'facebook_id', 'email'];

    protected function casts(): array
    {
        return ['publico' => 'boolean', 'ultimo_acceso_at' => 'datetime'];
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(Partida::class);
    }

    public function urlFoto(): ?string
    {
        return $this->foto ? '/storage/'.$this->foto : null;
    }

    /** Borra la cuenta, sus partidas y su foto (botón "Borrar mis datos" y solicitudes de Meta). */
    public function borrarDatos(): void
    {
        if ($this->foto) {
            Storage::disk('public')->delete($this->foto);
        }

        $this->delete();
    }
}
