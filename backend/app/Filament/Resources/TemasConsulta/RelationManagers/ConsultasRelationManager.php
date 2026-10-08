<?php

namespace App\Filament\Resources\TemasConsulta\RelationManagers;

use App\Filament\Resources\Consultas\ConsultaResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/** Todas las formas en que la gente ha preguntado este tema. */
class ConsultasRelationManager extends RelationManager
{
    protected static string $relationship = 'consultas';

    protected static ?string $title = 'Preguntas de este tema';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return ConsultaResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        return ConsultaResource::table($table);
    }
}
