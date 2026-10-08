<?php

namespace App\Filament\Resources\Colaboraciones\Pages;

use App\Filament\Resources\Colaboraciones\ColaboracionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListColaboraciones extends ListRecords
{
    protected static string $resource = ColaboracionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
