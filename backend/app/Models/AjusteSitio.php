<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AjusteSitio extends Model
{
    /** clave => [etiqueta, valor inicial] */
    public const AJUSTES = [
        'nombre' => ['Nombre de la comunidad', 'Andantes del Asfalto'],
        'lema' => ['Lema', null],
        'url_facebook' => ['Página de Facebook', 'https://www.facebook.com/AndantesDelAsfalto'],
        'seguidores' => ['Seguidores en Facebook', null],
        'logo' => ['Logo (de Facebook)', null],
        'portada' => ['Portada (de Facebook)', null],
        'facebook_sincronizado_at' => ['Última sincronización con Facebook', null],
        'facebook_error' => ['Error de la última sincronización', null],
        'url_instagram' => ['Perfil de Instagram', null],
        'seguidores_instagram' => ['Seguidores en Instagram', null],
        'instagram_usuario' => ['Cuenta de Instagram vinculada a la página', null],
        'url_tiktok' => ['Perfil de TikTok', null],
        'seguidores_tiktok' => ['Seguidores en TikTok', null],
        'url_youtube' => ['Canal de YouTube', null],
        'seguidores_youtube' => ['Suscriptores en YouTube', null],
        'youtube_error' => ['Error al consultar YouTube', null],
    ];

    /** Redes que suman al total de seguidores: red => [clave del enlace, clave de seguidores]. */
    public const REDES = [
        'facebook' => ['url_facebook', 'seguidores'],
        'instagram' => ['url_instagram', 'seguidores_instagram'],
        'tiktok' => ['url_tiktok', 'seguidores_tiktok'],
        'youtube' => ['url_youtube', 'seguidores_youtube'],
    ];

    protected $table = 'ajustes_sitio';

    protected $primaryKey = 'clave';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['clave', 'etiqueta', 'valor'];

    /** @return array<string, string|null> */
    public static function todos(): array
    {
        return static::query()->pluck('valor', 'clave')->all();
    }

    public static function valor(string $clave): ?string
    {
        return static::query()->whereKey($clave)->value('valor');
    }

    public static function fijar(string $clave, ?string $valor): void
    {
        $ajuste = static::firstOrNew(['clave' => $clave], ['etiqueta' => static::AJUSTES[$clave][0] ?? $clave]);
        $ajuste->valor = $valor;
        $ajuste->save();
    }

    /** Lo llena una sincronización: editarlo a mano no serviría (se sobrescribiría). */
    public function esAutomatico(): bool
    {
        $facebook = filled(config('services.facebook.page_token'));

        return match ($this->clave) {
            'seguidores', 'logo', 'portada', 'facebook_sincronizado_at', 'facebook_error', 'instagram_usuario' => $facebook,
            // Solo si la sincronización encontró un Instagram profesional vinculado a la página.
            'seguidores_instagram' => $facebook && filled(static::valor('instagram_usuario')),
            'seguidores_youtube', 'youtube_error' => filled(config('services.youtube.key')) && filled(static::valor('url_youtube')),
            default => false,
        };
    }
}
