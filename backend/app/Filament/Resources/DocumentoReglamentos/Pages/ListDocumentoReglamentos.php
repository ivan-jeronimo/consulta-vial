<?php

namespace App\Filament\Resources\DocumentoReglamentos\Pages;

use App\Filament\Resources\DocumentoReglamentos\DocumentoReglamentoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentoReglamentos extends ListRecords
{
    protected static string $resource = DocumentoReglamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
