<?php

namespace App\Filament\Resources\Dinamicas\Pages;

use App\Filament\Resources\Dinamicas\DinamicaResource;
use App\Services\Facebook\SincronizadorFacebook;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDinamica extends EditRecord
{
    protected static string $resource = DinamicaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(SincronizadorFacebook::class)->miniaturasDeDinamica($this->record);
    }
}
