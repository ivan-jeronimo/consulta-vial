<?php

namespace App\Filament\Widgets;

use App\Enums\EstatusProcesamiento;
use App\Enums\EstatusVigencia;
use App\Models\DocumentoReglamento;
use App\Models\ReglamentoArticulo;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class IngestaStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '10s';

    protected static ?int $sort = 10;

    protected ?string $heading = 'Documentos';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $porEstatus = DocumentoReglamento::query()
            ->selectRaw('estatus_procesamiento, count(*) as total')
            ->groupBy('estatus_procesamiento')
            ->pluck('total', 'estatus_procesamiento');

        $enCola = ($porEstatus[EstatusProcesamiento::Pendiente->value] ?? 0) + ($porEstatus[EstatusProcesamiento::Procesando->value] ?? 0);
        $conError = $porEstatus[EstatusProcesamiento::Error->value] ?? 0;

        return [
            Stat::make('Reglamentos consultables', DocumentoReglamento::consultable()->count())
                ->description(DocumentoReglamento::where('estatus_vigencia', EstatusVigencia::Derogado)->count().' derogados')
                ->color('success'),
            Stat::make('Artículos indexados', number_format(
                ReglamentoArticulo::whereHas('documento', fn ($q) => $q->consultable())->count()
            ))->description('Disponibles para el bot'),
            Stat::make('En cola / procesando', $enCola)->color($enCola ? 'warning' : 'gray'),
            Stat::make('Con error', $conError)
                ->color($conError ? 'danger' : 'gray')
                ->description($conError ? 'Revisa y re-procesa desde Reglamentos' : null),
        ];
    }
}
