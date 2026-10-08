<?php

namespace App\Filament\Resources\PreguntasReto\Pages;

use App\Filament\Resources\PreguntasReto\PreguntaRetoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPreguntasReto extends ListRecords
{
    protected static string $resource = PreguntaRetoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PreguntaRetoResource::accionGenerar(),
            CreateAction::make()->label('Escribir a mano'),
        ];
    }
}
