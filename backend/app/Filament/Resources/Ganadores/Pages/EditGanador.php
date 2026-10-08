<?php

namespace App\Filament\Resources\Ganadores\Pages;

use App\Filament\Resources\Ganadores\GanadorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGanador extends EditRecord
{
    protected static string $resource = GanadorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
