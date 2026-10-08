<?php

namespace App\Filament\Resources\Dinamicas\Pages;

use App\Filament\Resources\Dinamicas\DinamicaResource;
use App\Services\Facebook\SincronizadorFacebook;
use Filament\Resources\Pages\CreateRecord;

class CreateDinamica extends CreateRecord
{
    protected static string $resource = DinamicaResource::class;

    protected function afterCreate(): void
    {
        app(SincronizadorFacebook::class)->miniaturasDeDinamica($this->record);
    }
}
