<?php

namespace App\Filament\Resources\PublicacionesFacebook;

use App\Filament\Resources\Dinamicas\DinamicaResource;
use App\Filament\Resources\PublicacionesFacebook\Pages\ListPublicacionesFacebook;
use App\Models\PublicacionFacebook;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Publicaciones con #dinamica que trae la sincronización. Una dinámica tiene
 * anuncio, recordatorios y entrega: del anuncio se crea la dinámica y el resto se ignora.
 */
class PublicacionFacebookResource extends Resource
{
    protected static ?string $model = PublicacionFacebook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Sitio Andantes';

    protected static ?string $navigationLabel = 'Publicaciones #dinamica';

    protected static ?string $modelLabel = 'publicación';

    protected static ?string $pluralModelLabel = 'publicaciones con #dinamica';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return PublicacionFacebook::pendiente()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('publicada_at', 'desc')
            ->columns([
                ImageColumn::make('imagen')->disk('public'),
                TextColumn::make('mensaje')->wrap()->limit(160)->searchable(),
                TextColumn::make('publicada_at')->label('Publicada')->date('d/m/Y')->sortable(),
                TextColumn::make('estado')->badge()
                    ->formatStateUsing(fn (string $state) => ['pendiente' => 'Pendiente', 'usada' => 'Dinámica creada', 'ignorada' => 'Ignorada'][$state] ?? $state)
                    ->color(fn (string $state) => ['pendiente' => 'warning', 'usada' => 'success'][$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->options(['pendiente' => 'Pendientes', 'usada' => 'Dinámica creada', 'ignorada' => 'Ignoradas'])
                    ->default('pendiente'),
            ])
            ->recordActions([
                Action::make('crearDinamica')
                    ->label('Crear dinámica')
                    ->icon(Heroicon::OutlinedGift)
                    ->visible(fn (PublicacionFacebook $record) => $record->estado === 'pendiente')
                    ->action(function (PublicacionFacebook $record, Action $action) {
                        $dinamica = $record->crearDinamica();
                        $action->redirect(DinamicaResource::getUrl('edit', ['record' => $dinamica]));
                    }),
                Action::make('verDinamica')
                    ->label('Ver dinámica')
                    ->icon(Heroicon::OutlinedGift)
                    ->color('gray')
                    ->visible(fn (PublicacionFacebook $record) => $record->dinamica_id !== null)
                    ->url(fn (PublicacionFacebook $record) => DinamicaResource::getUrl('edit', ['record' => $record->dinamica_id])),
                Action::make('ignorar')
                    ->label('Ignorar')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('gray')
                    ->visible(fn (PublicacionFacebook $record) => $record->estado === 'pendiente')
                    ->action(fn (PublicacionFacebook $record) => $record->update(['estado' => 'ignorada'])),
                Action::make('restaurar')
                    ->label('Regresar a pendientes')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (PublicacionFacebook $record) => $record->estado === 'ignorada')
                    ->action(fn (PublicacionFacebook $record) => $record->update(['estado' => 'pendiente'])),
                Action::make('ver')
                    ->label('Facebook')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (PublicacionFacebook $record) => filled($record->url))
                    ->url(fn (PublicacionFacebook $record) => $record->url, shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPublicacionesFacebook::route('/'),
        ];
    }
}
