<?php

namespace App\Filament\Resources\Ganadores;

use App\Filament\Resources\Ganadores\Pages\CreateGanador;
use App\Filament\Resources\Ganadores\Pages\EditGanador;
use App\Filament\Resources\Ganadores\Pages\ListGanadores;
use App\Models\Ganador;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class GanadorResource extends Resource
{
    protected static ?string $model = Ganador::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Sitio Andantes';

    protected static ?string $modelLabel = 'ganador';

    protected static ?string $pluralModelLabel = 'ganadores';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')->required()->maxLength(150),
            Select::make('dinamica_id')->label('Dinámica')->relationship('dinamica', 'titulo')->searchable()->preload(),
            TextInput::make('premio')->maxLength(200)->helperText('Déjalo vacío para usar el premio de la dinámica.'),
            DatePicker::make('fecha'),
            Toggle::make('consentimiento')->label('Aceptó aparecer en el sitio')
                ->helperText('Sin este permiso, el ganador se guarda pero no se publica.'),
            FileUpload::make('foto')->image()->disk('public')->directory('sitio/ganadores'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('fecha', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('dinamica'))
            ->columns([
                ImageColumn::make('foto')->disk('public')->circular(),
                TextColumn::make('nombre')->searchable(),
                TextColumn::make('dinamica.titulo')->label('Dinámica')->placeholder('—')->wrap(),
                TextColumn::make('premio')->state(fn (Ganador $record) => $record->premio ?? $record->dinamica?->premio)->placeholder('—'),
                TextColumn::make('fecha')->date('d/m/Y')->sortable()->placeholder('—'),
                ToggleColumn::make('consentimiento')->label('Publicable'),
            ])
            ->filters([
                SelectFilter::make('dinamica_id')->label('Dinámica')->relationship('dinamica', 'titulo')->searchable()->preload(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGanadores::route('/'),
            'create' => CreateGanador::route('/create'),
            'edit' => EditGanador::route('/{record}/edit'),
        ];
    }
}
