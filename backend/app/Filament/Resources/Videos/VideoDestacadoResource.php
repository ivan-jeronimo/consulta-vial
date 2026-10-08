<?php

namespace App\Filament\Resources\Videos;

use App\Filament\Resources\Videos\Pages\CreateVideoDestacado;
use App\Filament\Resources\Videos\Pages\EditVideoDestacado;
use App\Filament\Resources\Videos\Pages\ListVideosDestacados;
use App\Jobs\SincronizarFacebookJob;
use App\Models\VideoDestacado;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class VideoDestacadoResource extends Resource
{
    protected static ?string $model = VideoDestacado::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Sitio Andantes';

    protected static ?string $modelLabel = 'video';

    protected static ?string $pluralModelLabel = 'videos';

    protected static ?string $recordTitleAttribute = 'titulo';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        $deFacebook = fn (?VideoDestacado $record) => filled($record?->facebook_id);

        return $schema->components([
            TextInput::make('titulo')->label('Título')->required()->maxLength(200)->columnSpanFull(),
            TextInput::make('url')->label('Enlace de Facebook')->url()->required()->maxLength(500)
                ->disabled($deFacebook)
                ->helperText('Copia el enlace del video o reel desde Facebook (Compartir → Copiar enlace).'),
            TextInput::make('vistas')->label('Reproducciones')->numeric()->minValue(0)
                ->disabled($deFacebook)
                ->helperText(fn (?VideoDestacado $record) => $deFacebook($record) ? 'Se actualiza sola desde Facebook.' : null),
            DatePicker::make('publicado_el')->label('Publicado el')->disabled($deFacebook),
            Toggle::make('fijado')->label('Fijar arriba')->helperText('Aparece antes que los más vistos.'),
            Toggle::make('visible')->default(true),
            FileUpload::make('miniatura')->image()->disk('public')->directory('sitio/videos')
                ->helperText('Opcional. Los videos de Facebook traen la suya.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->ordenSitio())
            ->columns([
                ImageColumn::make('miniatura')->disk('public'),
                TextColumn::make('titulo')->label('Título')->searchable()->wrap()->limit(90),
                TextColumn::make('tipo')->badge()->color(fn (string $state) => $state === 'reel' ? 'warning' : 'gray'),
                TextColumn::make('vistas')->label('Reproducciones')->numeric()->sortable()->alignEnd()->placeholder('—'),
                TextColumn::make('publicado_el')->label('Publicado')->date('d/m/Y')->sortable()->placeholder('—'),
                ToggleColumn::make('fijado')->label('Fijado'),
                ToggleColumn::make('visible'),
            ])
            ->filters([
                SelectFilter::make('tipo')->options(['reel' => 'Reels', 'video' => 'Videos']),
                TernaryFilter::make('visible'),
            ])
            ->recordActions([
                Action::make('ver')->label('Ver')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (VideoDestacado $record) => $record->url, shouldOpenInNewTab: true),
                EditAction::make(),
                // Los de Facebook volverían en la siguiente sincronización: se ocultan.
                DeleteAction::make()->hidden(fn (VideoDestacado $record) => filled($record->facebook_id)),
            ]);
    }

    public static function accionSincronizar(): Action
    {
        return Action::make('sincronizarFacebook')
            ->label('Sincronizar ahora')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn () => filled(config('services.facebook.page_token')) || filled(config('services.youtube.key')))
            ->action(function () {
                SincronizarFacebookJob::dispatch();
                Notification::make()
                    ->title('Sincronizando con Facebook')
                    ->body('Tarda un par de minutos. Recarga la página para ver lo nuevo.')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVideosDestacados::route('/'),
            'create' => CreateVideoDestacado::route('/create'),
            'edit' => EditVideoDestacado::route('/{record}/edit'),
        ];
    }
}
