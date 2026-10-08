<?php

namespace App\Filament\Resources\Dinamicas\Pages;

use App\Filament\Resources\Dinamicas\DinamicaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDinamicas extends ListRecords
{
    protected static string $resource = DinamicaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
