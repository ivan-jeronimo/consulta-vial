<?php

namespace App\Filament\Resources\DocumentoReglamentos\Pages;

use App\Filament\Resources\DocumentoReglamentos\DocumentoReglamentoResource;
use App\Filament\Resources\DocumentoReglamentos\Tables\DocumentoReglamentosTable;
use App\Services\Ingesta\RegistroReglamentos;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDocumentoReglamento extends EditRecord
{
    protected static string $resource = DocumentoReglamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocumentoReglamentosTable::accionReprocesar()->after(fn () => $this->refreshFormData(['estatus_procesamiento'])),
            DocumentoReglamentosTable::accionCambiarVigencia()->after(fn () => $this->refreshFormData(['estatus_vigencia'])),
            DeleteAction::make()
                ->modalDescription('Se eliminarán el documento, todos sus artículos indexados y el archivo fuente.'),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(RegistroReglamentos::class)->actualizar($record, $data);
    }
}
