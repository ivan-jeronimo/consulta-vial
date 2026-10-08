<?php

namespace App\Filament\Resources\DocumentoReglamentos\Pages;

use App\Filament\Resources\DocumentoReglamentos\DocumentoReglamentoResource;
use App\Services\Ingesta\RegistroReglamentos;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateDocumentoReglamento extends CreateRecord
{
    protected static string $resource = DocumentoReglamentoResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $registro = app(RegistroReglamentos::class);

        $path = filled($data['texto'] ?? null)
            ? $registro->guardarTexto($data['texto'])
            : $data['archivo_path'];

        return $registro->registrar(
            [...Arr::except($data, ['texto', 'archivo_path', 'archivo_nombre_original']), 'creado_por' => auth()->id()],
            $path,
            $data['archivo_nombre_original'] ?? null,
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Documento en cola de procesamiento';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
