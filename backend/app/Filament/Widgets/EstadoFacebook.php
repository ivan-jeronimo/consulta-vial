<?php

namespace App\Filament\Widgets;

use App\Models\AjusteSitio;
use App\Models\PublicacionFacebook;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/** Seguidores por red y aviso si alguna sincronización falló (token vencido, permisos…). */
class EstadoFacebook extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $heading = 'Redes sociales';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return filled(config('services.facebook.page_token')) || filled(config('services.youtube.key'));
    }

    protected function getStats(): array
    {
        $ajustes = AjusteSitio::todos();
        $error = collect([$ajustes['facebook_error'] ?? null, $ajustes['youtube_error'] ?? null])->filter()->implode(' · ');
        $ultima = isset($ajustes['facebook_sincronizado_at']) ? Carbon::parse($ajustes['facebook_sincronizado_at']) : null;
        $pendientes = PublicacionFacebook::pendiente()->count();

        $porRed = collect(AjusteSitio::REDES)
            ->map(fn (array $claves) => $ajustes[$claves[1]] ?? null)
            ->filter(fn ($valor) => is_numeric($valor))
            ->map(fn ($valor) => (int) $valor);

        return [
            Stat::make('Seguidores en total', $porRed->isEmpty() ? '—' : number_format($porRed->sum()))
                ->description($porRed->map(fn (int $n, string $red) => ucfirst($red).' '.number_format($n))->implode(' · ') ?: null),
            Stat::make('Sincronización', $error ? 'Con error' : ($ultima ? $ultima->diffForHumans() : 'Pendiente'))
                ->description($error ?: 'Se repite cada 6 horas')
                ->color($error ? 'danger' : 'success'),
            Stat::make('Publicaciones #dinamica por revisar', $pendientes)
                ->color($pendientes ? 'warning' : 'gray'),
        ];
    }
}
