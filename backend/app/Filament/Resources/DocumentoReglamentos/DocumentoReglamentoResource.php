<?php

namespace App\Filament\Resources\DocumentoReglamentos;

use App\Filament\Resources\DocumentoReglamentos\Pages\CreateDocumentoReglamento;
use App\Filament\Resources\DocumentoReglamentos\Pages\EditDocumentoReglamento;
use App\Filament\Resources\DocumentoReglamentos\Pages\ListDocumentoReglamentos;
use App\Filament\Resources\DocumentoReglamentos\RelationManagers\ArticulosRelationManager;
use App\Filament\Resources\DocumentoReglamentos\Schemas\DocumentoReglamentoForm;
use App\Filament\Resources\DocumentoReglamentos\Tables\DocumentoReglamentosTable;
use App\Models\DocumentoReglamento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentoReglamentoResource extends Resource
{
    protected static ?string $model = DocumentoReglamento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'documento';

    protected static ?string $pluralModelLabel = 'documentos';

    protected static ?string $recordTitleAttribute = 'titulo';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return DocumentoReglamentoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentoReglamentosTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['estado', 'municipio']);
    }

    public static function getRelations(): array
    {
        return [
            ArticulosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentoReglamentos::route('/'),
            'create' => CreateDocumentoReglamento::route('/create'),
            'edit' => EditDocumentoReglamento::route('/{record}/edit'),
        ];
    }
}
