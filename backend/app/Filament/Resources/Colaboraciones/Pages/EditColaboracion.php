<?php

namespace App\Filament\Resources\Colaboraciones\Pages;

use App\Filament\Resources\Colaboraciones\ColaboracionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditColaboracion extends EditRecord
{
    protected static string $resource = ColaboracionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
