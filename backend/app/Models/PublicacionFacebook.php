<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PublicacionFacebook extends Model
{
    protected $table = 'publicaciones_facebook';

    protected $fillable = ['facebook_id', 'mensaje', 'url', 'imagen', 'publicada_at', 'estado', 'dinamica_id'];

    protected function casts(): array
    {
        return ['publicada_at' => 'datetime'];
    }

    public function dinamica(): BelongsTo
    {
        return $this->belongsTo(Dinamica::class);
    }

    public function scopePendiente(Builder $query): void
    {
        $query->where('estado', 'pendiente');
    }

    /** Primera línea con texto, sin hashtags; sirve de título. */
    public static function resumen(?string $texto, int $limite = 120): ?string
    {
        foreach (preg_split('/\R/u', (string) $texto) as $linea) {
            $linea = trim(preg_replace(['/#[\p{L}\p{N}_]+/u', '/\s+/u'], ['', ' '], $linea));
            if ($linea !== '') {
                return Str::limit($linea, $limite, '…');
            }
        }

        return null;
    }

    /** Crea la dinámica con lo que se puede sacar de la publicación; premio y cierre se completan a mano. */
    public function crearDinamica(): Dinamica
    {
        $dinamica = Dinamica::create([
            'facebook_post_id' => $this->facebook_id,
            'titulo' => static::resumen($this->mensaje, 200) ?? 'Dinámica del '.$this->publicada_at?->format('d/m/Y'),
            'descripcion' => $this->mensaje,
            'imagen' => $this->imagen,
            'url_publicacion' => $this->url,
            'inicia_el' => $this->publicada_at,
        ]);

        $this->update(['estado' => 'usada', 'dinamica_id' => $dinamica->id]);

        return $dinamica;
    }
}
