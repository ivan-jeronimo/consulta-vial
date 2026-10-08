<?php

namespace App\Filament\Resources\Municipios\Tables;

use App\Models\Municipio;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MunicipiosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('nombre')
            ->modifyQueryUsing(fn ($query) => $query->with('estado')->withCount('documentos'))
            ->columns([
                TextColumn::make('nombre')->searchable()->sortable(),
                TextColumn::make('estado.nombre')->label('Estado')->sortable(),
                TextColumn::make('clave_inegi')->label('Clave INEGI')->placeholder('—'),
                TextColumn::make('documentos_count')->label('Reglamentos')->numeric()->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('estado_id')->label('Estado')->relationship('estado', 'nombre')->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                // La FK impide borrar municipios con reglamentos asociados.
                DeleteAction::make()->hidden(fn (Municipio $record) => $record->documentos_count > 0),
            ]);
    }
}
