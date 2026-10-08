<?php

namespace App\Filament\Resources\PreguntasReto;

use App\Enums\TipoDocumento;
use App\Filament\Resources\PreguntasReto\Pages\CreatePreguntaReto;
use App\Filament\Resources\PreguntasReto\Pages\EditPreguntaReto;
use App\Filament\Resources\PreguntasReto\Pages\ListPreguntasReto;
use App\Jobs\GenerarPreguntasRetoJob;
use App\Models\DocumentoReglamento;
use App\Models\PreguntaReto;
use App\Models\ReglamentoArticulo;
use App\Services\Reto\GeneradorPreguntas;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

class PreguntaRetoResource extends Resource
{
    protected static ?string $model = PreguntaReto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Reto';

    protected static ?string $navigationLabel = 'Preguntas';

    protected static ?string $modelLabel = 'pregunta';

    protected static ?string $pluralModelLabel = 'preguntas del reto';

    protected static ?string $recordTitleAttribute = 'pregunta';

    public static function getNavigationBadge(): ?string
    {
        return PreguntaReto::where('estado', 'borrador')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Por revisar';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('pregunta')->required()->rows(2)->columnSpanFull(),
            Section::make('Opciones')
                ->description('Marca la correcta. En el juego se muestran en orden aleatorio.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    ...collect(range(0, 3))->map(fn (int $i) => TextInput::make("opciones.{$i}")
                        ->label('Opción '.chr(65 + $i))
                        ->required($i < 3)
                        ->maxLength(300))->all(),
                    Radio::make('correcta')
                        ->label('Respuesta correcta')
                        ->options(fn (Get $get) => collect(range(0, 3))
                            ->filter(fn (int $i) => filled($get("opciones.{$i}")))
                            ->mapWithKeys(fn (int $i) => [$i => chr(65 + $i).'. '.$get("opciones.{$i}")])
                            ->all())
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                ]),
            Textarea::make('explicacion')->label('Explicación')->rows(2)->columnSpanFull()
                ->helperText('Se muestra después de responder.'),
            TextInput::make('categoria')->label('Categoría')->required()->maxLength(150)
                ->datalist(fn () => PreguntaReto::query()->distinct()->orderBy('categoria')->pluck('categoria')->all()),
            TextInput::make('cita')->label('Fuente')->maxLength(300)
                ->helperText('Ej.: Guía de Examen de Manejo Oaxaca 2023 · Señales de tránsito'),
            ToggleButtons::make('estado')->options(PreguntaReto::ESTADOS)->inline()->default('aprobada')
                ->colors(['borrador' => 'warning', 'aprobada' => 'success', 'descartada' => 'gray']),
            TextEntry::make('texto_fuente')
                ->label('Texto original (para comprobar la pregunta)')
                ->state(fn (?PreguntaReto $record) => $record?->textoFuente()->implode("\n\n"))
                ->visible(fn (?PreguntaReto $record) => filled($record?->fuentes))
                ->extraAttributes(['class' => 'whitespace-pre-line'])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('pregunta')->wrap()->searchable()
                    ->description(fn (PreguntaReto $record) => '✔ '.$record->respuestaCorrecta()),
                TextColumn::make('categoria')->label('Categoría')->badge()->color('gray'),
                TextColumn::make('estado')->badge()
                    ->formatStateUsing(fn (string $state) => PreguntaReto::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => ['borrador' => 'warning', 'aprobada' => 'success'][$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('estado')->options(PreguntaReto::ESTADOS)->default('borrador'),
                SelectFilter::make('categoria')->label('Categoría')
                    ->options(fn () => PreguntaReto::query()->distinct()->orderBy('categoria')->pluck('categoria', 'categoria')->all()),
            ])
            ->recordActions([
                Action::make('aprobar')->icon(Heroicon::OutlinedCheck)->color('success')->iconButton()->tooltip('Aprobar')
                    ->visible(fn (PreguntaReto $record) => $record->estado !== 'aprobada')
                    ->action(fn (PreguntaReto $record) => $record->update(['estado' => 'aprobada'])),
                Action::make('descartar')->icon(Heroicon::OutlinedXMark)->color('gray')->iconButton()->tooltip('Descartar')
                    ->visible(fn (PreguntaReto $record) => $record->estado !== 'descartada')
                    ->action(fn (PreguntaReto $record) => $record->update(['estado' => 'descartada'])),
                EditAction::make()->iconButton()->tooltip('Revisar y editar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('aprobarSeleccion')->label('Aprobar')->icon(Heroicon::OutlinedCheck)->color('success')
                        ->action(fn (Collection $records) => $records->each->update(['estado' => 'aprobada']))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('descartarSeleccion')->label('Descartar')->icon(Heroicon::OutlinedXMark)
                        ->action(fn (Collection $records) => $records->each->update(['estado' => 'descartada']))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function accionGenerar(): Action
    {
        $guia = fn () => DocumentoReglamento::consultable()->where('tipo', TipoDocumento::Manual)->value('id');

        return Action::make('generar')
            ->label('Generar preguntas')
            ->icon(Heroicon::OutlinedSparkles)
            ->modalDescription('La IA escribe preguntas a partir del documento. Llegan como borrador para que las revises; cada corrida avanza sobre fragmentos que aún no se usaron.')
            ->schema([
                Select::make('documento_id')->label('Documento')
                    ->options(fn () => DocumentoReglamento::consultable()->orderBy('titulo')->pluck('titulo', 'id'))
                    ->default($guia)
                    ->required()
                    ->live(),
                Select::make('capitulo')->label('Capítulo')
                    ->placeholder('Todos')
                    ->options(fn (Get $get) => $get('documento_id')
                        ? ReglamentoArticulo::where('documento_id', $get('documento_id'))->whereNotNull('capitulo')
                            ->selectRaw('capitulo, min(orden) o')->groupBy('capitulo')->orderBy('o')->pluck('capitulo', 'capitulo')
                        : []),
                TextInput::make('lotes')->label('Cuántas')->numeric()->integer()->minValue(1)->maxValue(30)->default(3)
                    ->suffix('× '.GeneradorPreguntas::POR_LOTE.' fragmentos')
                    ->helperText(fn (Get $get) => $get('documento_id')
                        ? 'Quedan '.app(GeneradorPreguntas::class)->pendientes(DocumentoReglamento::find($get('documento_id')), $get('capitulo'))->count().' fragmentos sin usar. Cada lote da ~5-10 preguntas y tarda ~15 s.'
                        : null),
            ])
            ->action(function (array $data) {
                GenerarPreguntasRetoJob::dispatch((int) $data['documento_id'], $data['capitulo'] ?? null, (int) $data['lotes']);
                Notification::make()
                    ->title('Generando preguntas')
                    ->body('Aparecerán aquí como borrador en uno o dos minutos. Recarga para verlas.')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPreguntasReto::route('/'),
            'create' => CreatePreguntaReto::route('/create'),
            'edit' => EditPreguntaReto::route('/{record}/edit'),
        ];
    }
}
