<?php

namespace App\Filament\Resources\PreguntasReto\Pages;

use App\Filament\Resources\PreguntasReto\PreguntaRetoResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePreguntaReto extends CreateRecord
{
    protected static string $resource = PreguntaRetoResource::class;

    /** La opción D es opcional: si quedó vacía no se guarda. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['opciones'] = array_values(array_filter($data['opciones'] ?? [], 'filled'));

        return $data;
    }
}
