<?php

namespace App\Filament\Resources\TemasConsulta\Pages;

use App\Filament\Resources\TemasConsulta\TemaConsultaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTemaConsulta extends EditRecord
{
    protected static string $resource = TemaConsultaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->modalDescription('Las consultas se conservan, pero quedan sin tema.'),
        ];
    }
}
