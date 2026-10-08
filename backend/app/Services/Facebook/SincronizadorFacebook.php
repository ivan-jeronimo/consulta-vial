<?php

namespace App\Services\Facebook;

use App\Models\AjusteSitio;
use App\Models\Dinamica;
use App\Models\PublicacionFacebook;
use App\Models\VideoDestacado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Trae de la página de Facebook: seguidores, logo y portada, videos/reels con
 * sus reproducciones y las publicaciones con #dinamica (a una bandeja).
 *
 * Lo editado en el panel se respeta: de los videos solo se actualizan vistas,
 * enlace y fecha (título, fijado y visible se quedan como estén).
 */
class SincronizadorFacebook
{
    /** Miniaturas que se descargan: las que el sitio puede llegar a mostrar. */
    private const MINIATURAS = 24;

    public function __construct(private readonly GraphFacebook $graph) {}

    /** @return array{seguidores: int|null, instagram: int|null, videos: int, publicaciones_nuevas: int} */
    public function sincronizar(): array
    {
        $paginaId = config('services.facebook.page_id');

        if (! $this->graph->configurado() || blank($paginaId)) {
            throw new RuntimeException('Faltan FACEBOOK_PAGE_ID o FACEBOOK_PAGE_TOKEN en .env.');
        }

        try {
            $resumen = [
                'seguidores' => $this->pagina($paginaId),
                'instagram' => $this->instagram($paginaId),
                'videos' => $this->videos($paginaId),
                'publicaciones_nuevas' => $this->publicaciones($paginaId),
            ];
        } catch (Throwable $e) {
            AjusteSitio::fijar('facebook_error', now()->format('d/m/Y H:i').' — '.$e->getMessage());
            throw $e;
        }

        AjusteSitio::fijar('facebook_sincronizado_at', now()->toIso8601String());
        AjusteSitio::fijar('facebook_error', null);

        return $resumen;
    }

    private function pagina(string $paginaId): ?int
    {
        $pagina = $this->graph->get($paginaId, ['fields' => 'followers_count,fan_count,picture.type(large){url},cover{source}']);

        $seguidores = $pagina['followers_count'] ?? $pagina['fan_count'] ?? null;
        AjusteSitio::fijar('seguidores', $seguidores === null ? null : (string) $seguidores);

        $this->imagenDePagina('logo', $pagina['picture']['data']['url'] ?? null);
        $this->imagenDePagina('portada', $pagina['cover']['source'] ?? null);

        return $seguidores;
    }

    /**
     * Instagram profesional vinculado a la página (requiere el permiso
     * instagram_basic en el token). Sin vínculo o sin permiso, se deja como
     * esté (se puede capturar a mano) y la sincronización sigue.
     */
    private function instagram(string $paginaId): ?int
    {
        try {
            $cuenta = $this->graph->get($paginaId, ['fields' => 'instagram_business_account{username,followers_count}'])['instagram_business_account'] ?? null;
        } catch (Throwable $e) {
            Log::info('Instagram no disponible con el token de la página', ['error' => $e->getMessage()]);
            $cuenta = null;
        }

        if (! isset($cuenta['followers_count'], $cuenta['username'])) {
            AjusteSitio::fijar('instagram_usuario', null);

            return null;
        }

        AjusteSitio::fijar('instagram_usuario', $cuenta['username']);
        AjusteSitio::fijar('seguidores_instagram', (string) $cuenta['followers_count']);
        if (blank(AjusteSitio::valor('url_instagram'))) {
            AjusteSitio::fijar('url_instagram', "https://www.instagram.com/{$cuenta['username']}/");
        }

        return $cuenta['followers_count'];
    }

    /** El nombre del archivo depende de la imagen: si no cambió en Facebook, no se vuelve a bajar. */
    private function imagenDePagina(string $clave, ?string $url): void
    {
        if (! $url) {
            return;
        }

        $anterior = AjusteSitio::valor($clave);
        $ruta = "sitio/facebook/{$clave}-".substr(md5((string) parse_url($url, PHP_URL_PATH)), 0, 12).'.jpg';

        if ($anterior === $ruta && Storage::disk('public')->exists($ruta)) {
            return;
        }

        if ($this->descargar($url, $ruta)) {
            AjusteSitio::fijar($clave, $ruta);
            if ($anterior && $anterior !== $ruta) {
                Storage::disk('public')->delete($anterior);
            }
        }
    }

    private function videos(string $paginaId): int
    {
        $miniaturas = [];
        $total = 0;

        $videos = $this->graph->paginar("{$paginaId}/videos", [
            'fields' => 'id,title,description,permalink_url,created_time,thumbnails{uri,is_preferred},'
                .'video_insights.metric(total_video_views,fb_reels_total_plays){name,values}',
            'since' => $this->desde(),
            'limit' => 25,
        ]);

        foreach ($videos as $v) {
            $permalink = $v['permalink_url'] ?? "/{$paginaId}/videos/{$v['id']}/";

            $video = VideoDestacado::firstOrNew(['facebook_id' => $v['id']]);
            if (! $video->exists) {
                $video->titulo = $this->titulo($v);
            }
            $video->fill([
                'tipo' => str_contains($permalink, '/reel/') ? 'reel' : 'video',
                'url' => Str::start($permalink, 'https://www.facebook.com'),
                'vistas' => $this->reproducciones($v),
                'publicado_el' => Carbon::parse($v['created_time']),
            ])->save();

            $miniaturas[$v['id']] = $this->miniatura($v);
            $total++;
        }

        VideoDestacado::visible()->whereNotNull('facebook_id')->ordenSitio()->limit(self::MINIATURAS)->get()
            ->concat(VideoDestacado::whereIn('id', Dinamica::query()->get(array_values(Dinamica::VIDEOS))->flatMap->only(Dinamica::VIDEOS)->filter())->get())
            ->filter(fn (VideoDestacado $video) => ! $video->miniatura && isset($miniaturas[$video->facebook_id]))
            ->each(function (VideoDestacado $video) use ($miniaturas) {
                $ruta = $this->descargar($miniaturas[$video->facebook_id], "sitio/facebook/videos/{$video->facebook_id}.jpg");
                $video->update(['miniatura' => $ruta]);
            });

        return $total;
    }

    /**
     * Al asignar videos a una dinámica desde el panel: baja su miniatura en ese
     * momento (la sincronización solo baja las de los más vistos).
     */
    public function miniaturasDeDinamica(Dinamica $dinamica): void
    {
        if (! $this->graph->configurado()) {
            return;
        }

        foreach (array_keys(Dinamica::VIDEOS) as $relacion) {
            $video = $dinamica->{$relacion};
            if (! $video?->facebook_id || $video->miniatura) {
                continue;
            }

            try {
                $url = $this->miniatura($this->graph->get($video->facebook_id, ['fields' => 'thumbnails{uri,is_preferred}']));
            } catch (Throwable $e) {
                Log::warning('No se pudo consultar la miniatura de un video', ['video' => $video->facebook_id, 'error' => $e->getMessage()]);

                continue;
            }

            if ($url) {
                $video->update(['miniatura' => $this->descargar($url, "sitio/facebook/videos/{$video->facebook_id}.jpg")]);
            }
        }
    }

    private function publicaciones(string $paginaId): int
    {
        $nuevas = 0;

        $publicaciones = $this->graph->paginar("{$paginaId}/posts", [
            'fields' => 'id,message,permalink_url,created_time,full_picture',
            'since' => $this->desde(),
            'limit' => 100,
        ]);

        foreach ($publicaciones as $p) {
            // #dinamica, #Dinámica, #DINAMICA…; no #dinamicas2025 ni #dinamicaVial.
            if (! preg_match('/#dinamica(?![\p{L}\p{N}_])/u', Str::lower(Str::ascii($p['message'] ?? '')))) {
                continue;
            }

            $publicacion = PublicacionFacebook::firstOrNew(['facebook_id' => $p['id']]);
            $publicacion->fill([
                'mensaje' => $p['message'],
                'url' => $p['permalink_url'] ?? null,
                'publicada_at' => Carbon::parse($p['created_time']),
            ]);

            if (! $publicacion->exists) {
                $nuevas++;
                if (isset($p['full_picture'])) {
                    $publicacion->imagen = $this->descargar($p['full_picture'], 'sitio/facebook/publicaciones/'.Str::slug($p['id']).'.jpg');
                }
            }

            $publicacion->save();
        }

        return $nuevas;
    }

    private function desde(): int
    {
        return now()->subMonths(config('services.facebook.meses'))->timestamp;
    }

    private function titulo(array $video): string
    {
        return PublicacionFacebook::resumen($video['title'] ?? null)
            ?? PublicacionFacebook::resumen($video['description'] ?? null)
            ?? 'Video del '.Carbon::parse($video['created_time'])->format('d/m/Y');
    }

    /** Los reels reportan fb_reels_total_plays; los videos normales, total_video_views. */
    private function reproducciones(array $video): ?int
    {
        $valores = collect($video['video_insights']['data'] ?? [])
            ->map(fn (array $m) => $m['values'][0]['value'] ?? null)
            ->filter(fn ($valor) => is_numeric($valor));

        return $valores->isEmpty() ? null : (int) $valores->max();
    }

    private function miniatura(array $video): ?string
    {
        $miniaturas = collect($video['thumbnails']['data'] ?? []);

        return ($miniaturas->firstWhere('is_preferred', true) ?? $miniaturas->first())['uri'] ?? null;
    }

    /** Una imagen que no baja no detiene la sincronización: se reintenta en la siguiente. */
    private function descargar(string $url, string $ruta): ?string
    {
        try {
            return $this->graph->descargar($url, $ruta);
        } catch (Throwable $e) {
            Log::warning('No se pudo descargar una imagen de Facebook', ['ruta' => $ruta, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
