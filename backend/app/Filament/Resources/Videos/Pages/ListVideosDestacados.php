<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Resources\Videos\VideoDestacadoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVideosDestacados extends ListRecords
{
    protected static string $resource = VideoDestacadoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VideoDestacadoResource::accionSincronizar(),
            CreateAction::make()->label('Agregar a mano'),
        ];
    }
}
