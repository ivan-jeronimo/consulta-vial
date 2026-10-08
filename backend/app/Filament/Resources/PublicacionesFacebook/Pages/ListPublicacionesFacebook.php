<?php

namespace App\Filament\Resources\PublicacionesFacebook\Pages;

use App\Filament\Resources\PublicacionesFacebook\PublicacionFacebookResource;
use App\Filament\Resources\Videos\VideoDestacadoResource;
use Filament\Resources\Pages\ListRecords;

class ListPublicacionesFacebook extends ListRecords
{
    protected static string $resource = PublicacionFacebookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VideoDestacadoResource::accionSincronizar(),
        ];
    }
}
