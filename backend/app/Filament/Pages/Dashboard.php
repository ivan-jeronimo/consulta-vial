<?php

namespace App\Filament\Pages;

use App\Models\Estado;
use App\Models\Municipio;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Panel';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Select::make('periodo')
                        ->label('Periodo')
                        ->options([7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días', 365 => 'Último año'])
                        ->default(30)
                        ->selectablePlaceholder(false),

                    Select::make('estado_id')
                        ->label('Estado')
                        ->placeholder('Todos')
                        // Estados con documentos cargados o con consultas registradas.
                        ->options(fn () => Estado::query()
                            ->whereIn('id', fn ($q) => $q->select('estado_id')->from('documentos_reglamentos')->whereNotNull('estado_id'))
                            ->orWhereIn('id', fn ($q) => $q->select('estado_id')->from('consultas')->whereNotNull('estado_id'))
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id'))
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('municipio_id', null)),

                    Select::make('municipio_id')
                        ->label('Municipio')
                        ->placeholder('Todos')
                        ->options(fn (Get $get) => Municipio::where('estado_id', $get('estado_id'))->orderBy('nombre')->pluck('nombre', 'id'))
                        ->disabled(fn (Get $get) => blank($get('estado_id'))),
                ]),
        ]);
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
