<?php

namespace App\Http\Controllers;

use App\Models\AjusteSitio;
use App\Models\Colaboracion;
use App\Models\Dinamica;
use App\Models\Ganador;
use App\Models\VideoDestacado;
use Illuminate\Http\JsonResponse;

/**
 * Contenido público del sitio de la comunidad, en una sola petición.
 */
class SitioController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $ajustes = AjusteSitio::todos();
        // Solo las redes con enlace o número; el total suma las que tienen número.
        $redes = collect(AjusteSitio::REDES)
            ->map(fn (array $claves, string $red) => [
                'red' => $red,
                'url' => $ajustes[$claves[0]] ?? null,
                'seguidores' => is_numeric($ajustes[$claves[1]] ?? null) ? (int) $ajustes[$claves[1]] : null,
            ])
            ->filter(fn (array $r) => $r['url'] || $r['seguidores'] !== null)
            ->values();
        $seguidores = $redes->whereNotNull('seguidores')->isEmpty() ? null : $redes->sum('seguidores');

        $dinamicas = Dinamica::visible()
            ->with(['videoInicio', 'videoEnVivo', 'videoCierre', 'ganadores' => fn ($q) => $q->publicable()->orderByDesc('fecha')->orderBy('id')])
            ->orderByRaw('termina_el is null desc, termina_el desc')
            ->get();

        return response()->json([
            'comunidad' => [
                'nombre' => $ajustes['nombre'] ?? 'Andantes del Asfalto',
                'lema' => $ajustes['lema'] ?? null,
                'url_facebook' => $ajustes['url_facebook'] ?? null,
                'logo' => $this->archivo($ajustes['logo'] ?? null),
                'portada' => $this->archivo($ajustes['portada'] ?? null),
                'redes' => $redes,
            ],
            'estadisticas' => [
                'seguidores' => $seguidores,
                'dinamicas' => $dinamicas->reject(fn (Dinamica $d) => $d->estatus() === 'proxima')->count(),
                'premios_entregados' => Ganador::count(),
            ],
            'videos' => VideoDestacado::visible()->ordenSitio()->limit(12)->get()->map($this->video(...)),
            // Activas y próximas primero; de las terminadas, solo las más recientes.
            'dinamicas' => $dinamicas->filter(fn (Dinamica $d) => $d->estatus() === 'activa')
                ->concat($dinamicas->filter(fn (Dinamica $d) => $d->estatus() === 'proxima'))
                ->concat($dinamicas->filter(fn (Dinamica $d) => $d->estatus() === 'terminada')->take(6))
                ->map(fn (Dinamica $d) => [
                    'id' => $d->id,
                    'titulo' => $d->titulo,
                    'descripcion' => $d->descripcion,
                    'premio' => $d->premio,
                    'imagen' => $this->archivo($d->imagen),
                    'url_publicacion' => $d->url_publicacion,
                    'inicia_el' => $d->inicia_el?->toDateString(),
                    'termina_el' => $d->termina_el?->toDateString(),
                    'estatus' => $d->estatus(),
                    // Línea de tiempo: lanzamiento → sorteo en vivo → ganadores.
                    'video_inicio' => $d->videoInicio ? $this->video($d->videoInicio) : null,
                    'video_en_vivo' => $d->videoEnVivo ? $this->video($d->videoEnVivo) : null,
                    'video_cierre' => $d->videoCierre ? $this->video($d->videoCierre) : null,
                    'ganadores' => $d->ganadores->map(fn (Ganador $g) => $this->ganador($g->setRelation('dinamica', $d)))->values(),
                ])
                ->values(),
            // El sitio muestra unos cuantos y carga el resto con "Ver más".
            'ganadores' => Ganador::publicable()->with('dinamica:id,titulo,premio')
                ->orderByDesc('fecha')->orderByDesc('id')->limit(60)->get()
                ->map($this->ganador(...)),
            'colaboraciones' => Colaboracion::visible()->orderBy('orden')->orderByDesc('fecha')->get()
                ->map(fn (Colaboracion $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'descripcion' => $c->descripcion,
                    'logo' => $this->archivo($c->logo),
                    'url' => $c->url,
                ]),
        ])->setPublic()->setMaxAge(60);
    }

    private function ganador(Ganador $g): array
    {
        return [
            'id' => $g->id,
            'nombre' => $g->nombre,
            'premio' => $g->premio ?? $g->dinamica?->premio,
            'dinamica_id' => $g->dinamica_id,
            'dinamica' => $g->dinamica?->titulo,
            'foto' => $this->archivo($g->foto),
            'fecha' => $g->fecha?->toDateString(),
        ];
    }

    private function video(VideoDestacado $v): array
    {
        return [
            'id' => $v->id,
            'tipo' => $v->tipo,
            'titulo' => $v->titulo,
            'url' => $v->url,
            'embed' => $this->reproductor($v),
            'miniatura' => $this->archivo($v->miniatura),
            'vistas' => $v->vistas,
            'publicado_el' => $v->publicado_el?->toDateString(),
        ];
    }

    /** Reproductor oficial de Facebook; no necesita app ni token. */
    private function reproductor(VideoDestacado $video): string
    {
        // Con el id se arma la forma canónica /{página}/videos/{id}/ (sirve para reels y videos).
        $paginaId = config('services.facebook.page_id');
        $href = $video->facebook_id && $paginaId
            ? "https://www.facebook.com/{$paginaId}/videos/{$video->facebook_id}/"
            : $video->url;

        return 'https://www.facebook.com/plugins/video.php?'.http_build_query(['href' => $href, 'show_text' => 'false', 'autoplay' => 'true']);
    }

    /** Ruta relativa al mismo origen, así funciona igual en desarrollo (proxy de Vite) y en producción. */
    private function archivo(?string $ruta): ?string
    {
        return $ruta ? '/storage/'.ltrim($ruta, '/') : null;
    }
}
