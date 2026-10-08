<?php

namespace App\Filament\Resources\Ajustes;

use App\Filament\Resources\Ajustes\Pages\EditAjusteSitio;
use App\Filament\Resources\Ajustes\Pages\ListAjustesSitio;
use App\Models\AjusteSitio;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Las claves las crea AjustesSitioSeeder; aquí solo se editan sus valores. */
class AjusteSitioResource extends Resource
{
    protected static ?string $model = AjusteSitio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Sitio Andantes';

    protected static ?string $modelLabel = 'ajuste';

    protected static ?string $pluralModelLabel = 'ajustes del sitio';

    protected static ?string $recordTitleAttribute = 'etiqueta';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $esNumero = fn (?AjusteSitio $record) => str_starts_with((string) $record?->clave, 'seguidores');
        $esEnlace = fn (?AjusteSitio $record) => str_starts_with((string) $record?->clave, 'url_');
        $etiqueta = fn (?AjusteSitio $record) => $record?->etiqueta ?? 'Valor';

        return $schema->components([
            // Seguidores: solo el número (sin "k" ni comas), para poder sumarlos.
            TextInput::make('valor')->label($etiqueta)->visible($esNumero)
                ->integer()->minValue(0)
                ->helperText('Solo el número, sin comas ni "k". Ejemplo: 12500'),
            TextInput::make('valor')->label($etiqueta)->visible($esEnlace)
                ->url()->maxLength(500)
                ->placeholder(fn (?AjusteSitio $record) => match ($record?->clave) {
                    'url_youtube' => 'https://www.youtube.com/@tucanal',
                    'url_tiktok' => 'https://www.tiktok.com/@tucuenta',
                    'url_instagram' => 'https://www.instagram.com/tucuenta/',
                    default => 'https://',
                }),
            Textarea::make('valor')->label($etiqueta)
                ->visible(fn (?AjusteSitio $record) => ! $esNumero($record) && ! $esEnlace($record))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('etiqueta')->label('Ajuste')
                    ->description(fn (AjusteSitio $record) => $record->esAutomatico() ? 'Se actualiza desde Facebook' : null),
                TextColumn::make('valor')->placeholder('—')->wrap()->limit(120),
            ])
            ->recordActions([EditAction::make()->hidden(fn (AjusteSitio $record) => $record->esAutomatico())]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAjustesSitio::route('/'),
            'edit' => EditAjusteSitio::route('/{record}/edit'),
        ];
    }
}
