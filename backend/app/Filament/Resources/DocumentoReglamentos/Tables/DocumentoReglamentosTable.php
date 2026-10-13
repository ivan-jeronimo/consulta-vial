<?php

namespace App\Filament\Resources\DocumentoReglamentos\Tables;

use App\Enums\EstatusProcesamiento;
use App\Enums\EstatusVigencia;
use App\Enums\NivelJurisdiccion;
use App\Enums\TipoDocumento;
use App\Models\DocumentoReglamento;
use App\Services\Ingesta\RegistroReglamentos;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentoReglamentosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Refresca mientras haya ingestas en curso.
            ->poll(fn () => DocumentoReglamento::whereIn('estatus_procesamiento', [EstatusProcesamiento::Pendiente, EstatusProcesamiento::Procesando])->exists() ? '3s' : null)
            ->columns([
                TextColumn::make('titulo')
                    ->label('Título')
                    ->searchable()
                    ->wrap()
                    ->description(fn (DocumentoReglamento $r) => $r->archivo_nombre_original),
                IconColumn::make('url_publica')
                    ->label('Enlace')
                    ->state(fn (DocumentoReglamento $record) => filled($record->url_publica))
                    ->boolean()
                    ->tooltip(fn (DocumentoReglamento $record) => $record->url_publica ?: 'Falta el enlace público: el chat no podrá enlazar a este documento.')
                    ->url(fn (DocumentoReglamento $record) => $record->url_publica, shouldOpenInNewTab: true),
                TextColumn::make('tipo')->badge()->sortable(),
                TextColumn::make('nivel')->badge()->sortable(),
                TextColumn::make('estado.nombre')->label('Estado')->placeholder('—')->sortable(),
                TextColumn::make('municipio.nombre')->label('Municipio')->placeholder('—')->sortable()->searchable(),
                TextColumn::make('anio')->label('Año')->sortable(),
                TextColumn::make('estatus_vigencia')->label('Vigencia')->badge(),
                TextColumn::make('estatus_procesamiento')
                    ->label('Procesamiento')
                    ->badge()
                    ->tooltip(fn (DocumentoReglamento $r) => $r->error_mensaje),
                TextColumn::make('chunks_count')->label('Fragmentos')->numeric()->alignEnd(),
                TextColumn::make('procesado_at')->label('Procesado')->since()->dateTimeTooltip()->toggleable(),
                TextColumn::make('created_at')->label('Subido')->dateTime('d/m/Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tipo')->options(TipoDocumento::class),
                SelectFilter::make('nivel')->options(NivelJurisdiccion::class),
                SelectFilter::make('estado_id')->label('Estado')->relationship('estado', 'nombre')->searchable()->preload(),
                SelectFilter::make('estatus_vigencia')->label('Vigencia')->options(EstatusVigencia::class),
                SelectFilter::make('estatus_procesamiento')->label('Procesamiento')->options(EstatusProcesamiento::class),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    self::accionReprocesar(),
                    self::accionCambiarVigencia(),
                    DeleteAction::make()
                        ->modalDescription('Se eliminarán el documento, todos sus artículos indexados y el archivo fuente.'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function accionReprocesar(): Action
    {
        return Action::make('reprocesar')
            ->label('Re-procesar')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Se volverá a extraer, segmentar y generar embeddings. El bot seguirá usando la versión actual hasta que termine.')
            ->visible(fn (DocumentoReglamento $record) => app(RegistroReglamentos::class)->puedeReprocesar($record))
            ->action(function (DocumentoReglamento $record) {
                app(RegistroReglamentos::class)->reprocesar($record);
                Notification::make()->title('Documento en cola de procesamiento')->success()->send();
            });
    }

    public static function accionCambiarVigencia(): Action
    {
        return Action::make('cambiarVigencia')
            ->label(fn (DocumentoReglamento $record) => $record->estatus_vigencia === EstatusVigencia::Vigente ? 'Derogar' : 'Marcar vigente')
            ->icon(fn (DocumentoReglamento $record) => $record->estatus_vigencia === EstatusVigencia::Vigente ? 'heroicon-o-archive-box-x-mark' : 'heroicon-o-check-badge')
            ->color(fn (DocumentoReglamento $record) => $record->estatus_vigencia === EstatusVigencia::Vigente ? 'gray' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (DocumentoReglamento $record) => $record->estatus_vigencia === EstatusVigencia::Vigente
                ? 'El bot dejará de consultar sus artículos de inmediato.'
                : 'El bot volverá a consultar sus artículos.')
            ->action(function (DocumentoReglamento $record) {
                $nuevo = $record->estatus_vigencia === EstatusVigencia::Vigente ? EstatusVigencia::Derogado : EstatusVigencia::Vigente;
                app(RegistroReglamentos::class)->actualizar($record, ['estatus_vigencia' => $nuevo]);
                Notification::make()->title("Documento marcado como {$nuevo->getLabel()}")->success()->send();
            });
    }
}
