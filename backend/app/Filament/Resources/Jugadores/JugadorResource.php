<?php

namespace App\Filament\Resources\Jugadores;

use App\Filament\Resources\Jugadores\Pages\ListJugadores;
use App\Models\Jugador;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Quién juega el reto. Solo lectura, salvo sacar a alguien del ranking o borrar sus datos. */
class JugadorResource extends Resource
{
    protected static ?string $model = Jugador::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Reto';

    protected static ?string $modelLabel = 'jugador';

    protected static ?string $pluralModelLabel = 'jugadores';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ultimo_acceso_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query
                ->withCount(['partidas as partidas_terminadas' => fn ($q) => $q->whereNotNull('terminada_at')])
                ->withMax(['partidas as mejor_puntaje' => fn ($q) => $q->whereNotNull('terminada_at')], 'puntos'))
            ->columns([
                ImageColumn::make('foto')->disk('public')->circular(),
                TextColumn::make('nombre')->searchable(),
                TextColumn::make('partidas_terminadas')->label('Partidas')->numeric()->sortable()->alignEnd(),
                TextColumn::make('mejor_puntaje')->label('Mejor puntaje')->numeric()->sortable()->alignEnd()->placeholder('—'),
                ToggleColumn::make('publico')->label('En el ranking'),
                TextColumn::make('ultimo_acceso_at')->label('Último acceso')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('borrar')->label('Borrar datos')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Se borran su cuenta, sus partidas y su foto. Si vuelve a entrar con Facebook, empieza de cero.')
                    ->action(fn (Jugador $record) => $record->borrarDatos()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJugadores::route('/'),
        ];
    }
}
