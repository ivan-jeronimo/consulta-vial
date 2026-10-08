<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TemasConsulta\TemaConsultaResource;
use App\Filament\Widgets\Concerns\FiltraConsultas;
use App\Models\TemaConsulta;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TemasMasConsultados extends TableWidget
{
    use FiltraConsultas;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /** Solo los temas sin cobertura: lo que preguntan y no se puede responder. */
    protected bool $soloSinCobertura = false;

    public function table(Table $table): Table
    {
        $filtrar = fn (Builder $q) => $this->filtrar($q);
        $sinCobertura = fn (Builder $q) => $this->filtrar($q)->where('cubierto', false);

        return $table
            ->heading($this->soloSinCobertura ? 'Lo que preguntan y no se puede responder' : 'Temas más consultados')
            ->description($this->soloSinCobertura
                ? 'Temas que los documentos cargados no cubren: indican qué reglamento o municipio conviene agregar.'
                : 'Preguntas agrupadas por intención, aunque estén redactadas distinto.')
            ->query(fn () => TemaConsulta::query()
                ->whereHas('consultas', $this->soloSinCobertura ? $sinCobertura : $filtrar)
                ->withCount(['consultas as total' => $filtrar])
                ->withCount(['consultas as sin_cobertura' => $sinCobertura])
                ->withCount(['consultas as utiles' => fn (Builder $q) => $this->filtrar($q)->where('valoracion', 1)])
                ->withCount(['consultas as no_utiles' => fn (Builder $q) => $this->filtrar($q)->where('valoracion', -1)])
                ->withMax(['consultas as ultima' => $filtrar], 'created_at'))
            ->defaultSort($this->soloSinCobertura ? 'sin_cobertura' : 'total', 'desc')
            ->paginated([10])
            ->columns([
                TextColumn::make('etiqueta')->label('Tema')->wrap()->searchable(),
                TextColumn::make('total')->label('Consultas')->numeric()->sortable()->alignEnd(),
                TextColumn::make('sin_cobertura')
                    ->label('Sin cobertura')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),
                TextColumn::make('valoracion')
                    ->label('👍 / 👎')
                    ->state(fn (TemaConsulta $t) => "{$t->utiles} / {$t->no_utiles}")
                    ->alignEnd()
                    ->visible(! $this->soloSinCobertura),
                TextColumn::make('ultima')->label('Última')->since()->sortable(),
            ])
            ->recordUrl(fn (TemaConsulta $t) => TemaConsultaResource::getUrl('edit', ['record' => $t]));
    }
}
