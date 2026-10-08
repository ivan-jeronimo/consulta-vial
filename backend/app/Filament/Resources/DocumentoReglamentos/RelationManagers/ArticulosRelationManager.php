<?php

namespace App\Filament\Resources\DocumentoReglamentos\RelationManagers;

use App\Models\ReglamentoArticulo;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Vista de solo lectura de los fragmentos indexados, para que el administrador
 * verifique que la segmentación por artículos salió bien.
 */
class ArticulosRelationManager extends RelationManager
{
    protected static string $relationship = 'articulos';

    protected static ?string $title = 'Artículos indexados';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('titulo')->placeholder('—'),
                TextEntry::make('capitulo')->placeholder('—'),
                TextEntry::make('seccion')->label('Sección')->placeholder('—'),
                TextEntry::make('articulo')->label('Artículo')->placeholder('—'),
                TextEntry::make('fraccion')->label('Fracción')->placeholder('—'),
                TextEntry::make('inciso')->placeholder('—'),
                TextEntry::make('tokens_aprox')->label('Tokens aprox.'),
                TextEntry::make('contexto')
                    ->label('Encabezado del artículo')
                    ->columnSpanFull()
                    ->visible(fn ($state) => filled($state))
                    ->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('contenido')
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'whitespace-pre-line']),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('orden')
            ->recordTitle(fn (ReglamentoArticulo $r) => $r->articulo ? "Artículo {$r->articulo}" : "Fragmento {$r->orden}")
            ->columns([
                TextColumn::make('orden')->label('#')->sortable(),
                TextColumn::make('articulo')->label('Artículo')->placeholder('—')->searchable(),
                TextColumn::make('fraccion')->label('Fracción')->placeholder('—'),
                TextColumn::make('inciso')->placeholder('—'),
                TextColumn::make('capitulo')->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('contenido')->limit(140)->wrap()->searchable(),
                TextColumn::make('tokens_aprox')->label('Tokens')->numeric()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('4xl'),
            ]);
    }
}
