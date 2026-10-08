<?php

namespace App\Filament\Resources\Ganadores\Pages;

use App\Filament\Resources\Ganadores\GanadorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGanadores extends ListRecords
{
    protected static string $resource = GanadorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
