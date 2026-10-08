<?php

namespace App\Filament\Resources\PreguntasReto\Pages;

use App\Filament\Resources\PreguntasReto\PreguntaRetoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPreguntaReto extends EditRecord
{
    protected static string $resource = PreguntaRetoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['opciones'] = array_values(array_filter($data['opciones'] ?? [], 'filled'));

        return $data;
    }
}
