<?php

namespace App\Filament\Resources\Colaboraciones;

use App\Filament\Resources\Colaboraciones\Pages\CreateColaboracion;
use App\Filament\Resources\Colaboraciones\Pages\EditColaboracion;
use App\Filament\Resources\Colaboraciones\Pages\ListColaboraciones;
use App\Models\Colaboracion;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class ColaboracionResource extends Resource
{
    protected static ?string $model = Colaboracion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Sitio Andantes';

    protected static ?string $modelLabel = 'colaboración';

    protected static ?string $pluralModelLabel = 'colaboraciones';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')->label('Marca o persona')->required()->maxLength(150),
            TextInput::make('url')->label('Enlace')->url()->maxLength(500),
            Textarea::make('descripcion')->label('Descripción')->rows(3)->columnSpanFull(),
            DatePicker::make('fecha'),
            TextInput::make('orden')->numeric()->default(0)->helperText('Menor número = aparece primero.'),
            Toggle::make('visible')->default(true),
            FileUpload::make('logo')->image()->disk('public')->directory('sitio/colaboraciones'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('orden')
            ->reorderable('orden')
            ->columns([
                ImageColumn::make('logo')->disk('public'),
                TextColumn::make('nombre')->searchable(),
                TextColumn::make('fecha')->date('d/m/Y')->sortable()->placeholder('—'),
                ToggleColumn::make('visible'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListColaboraciones::route('/'),
            'create' => CreateColaboracion::route('/create'),
            'edit' => EditColaboracion::route('/{record}/edit'),
        ];
    }
}
