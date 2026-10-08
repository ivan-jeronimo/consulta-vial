<?php

namespace App\Filament\Resources\Municipios\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class MunicipioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('estado_id')
                    ->label('Estado')
                    ->relationship('estado', 'nombre')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('nombre')
                    ->required()
                    ->maxLength(150)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('estado_id', $get('estado_id'))),

                TextInput::make('clave_inegi')
                    ->label('Clave INEGI')
                    ->numeric()
                    ->length(3)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('estado_id', $get('estado_id'))),
            ]);
    }
}
