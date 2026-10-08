<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FiltraConsultas;
use Filament\Widgets\ChartWidget;

class ConsultasPorUbicacion extends ChartWidget
{
    use FiltraConsultas;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Dónde preguntan';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $filas = $this->consultas()
            ->leftJoin('estados', 'estados.id', '=', 'consultas.estado_id')
            ->leftJoin('municipios', 'municipios.id', '=', 'consultas.municipio_id')
            ->selectRaw("coalesce(municipios.nombre || ', ' || estados.nombre, estados.nombre, 'Sin ubicación') as lugar")
            ->selectRaw('count(*) as total')
            ->groupBy('lugar')
            ->orderByDesc('total')
            ->limit(8)
            ->toBase()
            ->get();

        return [
            'datasets' => [[
                'label' => 'Consultas',
                'data' => $filas->pluck('total')->map(fn ($n) => (int) $n)->all(),
                'backgroundColor' => '#059669',
                'borderRadius' => 4,
            ]],
            'labels' => $filas->pluck('lugar')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['ticks' => ['precision' => 0]]],
        ];
    }
}
