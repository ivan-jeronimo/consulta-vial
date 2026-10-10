<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FiltraConsultas;
use App\Models\Consulta;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class ResumenConsultas extends StatsOverviewWidget
{
    use FiltraConsultas;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $r = $this->consultas()
            ->selectRaw('count(*) as total')
            ->selectRaw('count(distinct visitante) as usuarios')
            ->selectRaw('count(*) filter (where cubierto) as cubiertas')
            ->selectRaw('count(*) filter (where cubierto is false) as sin_cobertura')
            ->selectRaw('count(*) filter (where valoracion = 1) as utiles')
            ->selectRaw('count(*) filter (where valoracion is not null) as valoradas')
            ->selectRaw('avg(latencia_ms) as latencia')
            ->toBase()
            ->first();

        $porDia = $this->consultas()
            ->selectRaw('date(created_at) as dia, count(*) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->pluck('total', 'dia');

        $periodo = "Últimos {$this->dias()} días";
        $pct = fn ($parte, $total) => $total > 0 ? round(100 * $parte / $total).' %' : '—';

        // Hoy, por sitio: cuánto del tope diario se lleva gastado (cada consulta = una llamada al LLM).
        $hoy = Consulta::query()->whereDate('created_at', today())
            ->selectRaw("coalesce(origen, 'directo') as sitio, count(*) as total")
            ->groupBy('sitio')
            ->pluck('total', 'sitio');
        $tope = (int) config('rag.limites.total_al_dia');

        return [
            Stat::make('Consultas de hoy', number_format($hoy->sum()).($tope ? " de {$tope}" : ''))
                ->description($hoy->isEmpty() ? 'Sin consultas todavía' : $hoy->map(fn ($n, $sitio) => "{$sitio}: {$n}")->implode(' · '))
                ->color($tope && $hoy->sum() >= 0.8 * $tope ? 'warning' : 'gray'),

            Stat::make('Consultas', number_format($r->total))
                ->description($periodo)
                ->chart($porDia->values()->all() ?: [0])
                ->color('primary'),

            Stat::make('Usuarios únicos', number_format($r->usuarios))
                ->description($r->usuarios ? round($r->total / $r->usuarios, 1).' consultas por usuario' : $periodo),

            Stat::make('Respondidas con fundamento', $pct($r->cubiertas, $r->total))
                ->description(number_format($r->sin_cobertura).' sin cobertura en los documentos')
                ->color($r->total && $r->sin_cobertura / $r->total > 0.2 ? 'warning' : 'success'),

            Stat::make('Valoradas como útiles', $pct($r->utiles, $r->valoradas))
                ->description(number_format($r->valoradas).' valoraciones 👍/👎'),

            Stat::make('Tiempo de respuesta', $r->latencia ? number_format($r->latencia / 1000, 1).' s' : '—')
                ->description('Promedio'),
        ];
    }
}
