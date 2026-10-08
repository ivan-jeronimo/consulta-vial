<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FiltraConsultas;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;

class ConsultasPorDia extends ChartWidget
{
    use FiltraConsultas;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Consultas por día';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $filas = $this->consultas()
            ->selectRaw('date(created_at) as dia')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where cubierto is false) as sin_cobertura')
            ->groupBy('dia')
            ->toBase()
            ->get()
            ->keyBy('dia');

        // Todos los días del periodo, aunque no haya consultas.
        $dias = collect(CarbonPeriod::create($this->desde(), now()->startOfDay()))->map->toDateString();

        return [
            'datasets' => [
                [
                    'label' => 'Consultas',
                    'data' => $dias->map(fn ($d) => (int) ($filas[$d]->total ?? 0))->all(),
                    'borderColor' => '#059669',
                    'backgroundColor' => 'rgba(5, 150, 105, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Sin cobertura',
                    'data' => $dias->map(fn ($d) => (int) ($filas[$d]->sin_cobertura ?? 0))->all(),
                    'borderColor' => '#d97706',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $dias->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('j M'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
