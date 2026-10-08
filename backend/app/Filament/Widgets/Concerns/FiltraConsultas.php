<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Consulta;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Aplica a los widgets de métricas los filtros del dashboard: periodo
 * (últimos N días) y ubicación (estado / municipio).
 */
trait FiltraConsultas
{
    use InteractsWithPageFilters;

    protected function dias(): int
    {
        return (int) ($this->pageFilters['periodo'] ?? 30);
    }

    protected function desde(): Carbon
    {
        return now()->subDays($this->dias() - 1)->startOfDay();
    }

    /** Consultas del periodo y la ubicación elegidos. */
    protected function consultas(): Builder
    {
        return $this->filtrar(Consulta::query());
    }

    /** Aplica los filtros a cualquier query sobre `consultas` (también en subconsultas de withCount). */
    protected function filtrar(BuilderContract $query): BuilderContract
    {
        return $query
            ->where('consultas.created_at', '>=', $this->desde())
            ->when($this->pageFilters['estado_id'] ?? null, fn ($q, $id) => $q->where('consultas.estado_id', $id))
            ->when($this->pageFilters['municipio_id'] ?? null, fn ($q, $id) => $q->where('consultas.municipio_id', $id));
    }
}
