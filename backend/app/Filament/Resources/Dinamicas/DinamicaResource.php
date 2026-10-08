<?php

namespace App\Filament\Resources\Dinamicas;

use App\Filament\Resources\Dinamicas\Pages\CreateDinamica;
use App\Filament\Resources\Dinamicas\Pages\EditDinamica;
use App\Filament\Resources\Dinamicas\Pages\ListDinamicas;
use App\Models\Dinamica;
use App\Models\VideoDestacado;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class DinamicaResource extends Resource
{
    protected static ?string $model = Dinamica::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Sitio Andantes';

    protected static ?string $modelLabel = 'dinámica';

    protected static ?string $pluralModelLabel = 'dinámicas';

    protected static ?string $recordTitleAttribute = 'titulo';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('titulo')->label('Título')->required()->maxLength(200),
            TextInput::make('premio')->maxLength(200),
            Textarea::make('descripcion')->label('Descripción y reglas')->rows(4)->columnSpanFull(),
            DatePicker::make('inicia_el')->label('Inicia'),
            DatePicker::make('termina_el')->label('Termina')
                ->afterOrEqual(fn (Get $get) => $get('inicia_el') ?: null),
            TextInput::make('url_publicacion')->label('Publicación en Facebook')->url()->maxLength(500),
            Toggle::make('visible')->default(true),
            Section::make('Videos de la dinámica')
                ->description('Elige entre los videos de tu página; busca por su texto. En el sitio se ven como línea de tiempo.')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    static::selectorVideo('video_inicio_id', 'videoInicio', '1. Lanzamiento'),
                    static::selectorVideo('video_en_vivo_id', 'videoEnVivo', '2. En vivo')->helperText('El sorteo donde se elige al ganador.'),
                    static::selectorVideo('video_cierre_id', 'videoCierre', '3. Ganadores')->helperText('Anuncio o entrega del premio.'),
                ]),
            FileUpload::make('imagen')->image()->disk('public')->directory('sitio/dinamicas'),
        ]);
    }

    /** Elige entre los videos ya sincronizados de la página (los más recientes primero). */
    private static function selectorVideo(string $campo, string $relacion, string $etiqueta): Select
    {
        return Select::make($campo)
            ->label($etiqueta)
            ->relationship($relacion, 'titulo', fn ($query) => $query->orderByDesc('publicado_el'))
            ->getOptionLabelFromRecordUsing(fn (VideoDestacado $video) => sprintf(
                '%s · %s%s',
                $video->publicado_el?->format('d/m/Y') ?? 's/f',
                Str::limit($video->titulo, 70),
                $video->vistas ? ' · '.number_format($video->vistas).' repr.' : '',
            ))
            ->searchable(['titulo'])
            ->optionsLimit(30);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('inicia_el', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('ganadores'))
            ->columns([
                TextColumn::make('titulo')->label('Título')->searchable()->wrap(),
                TextColumn::make('premio')->placeholder('—')->wrap(),
                TextColumn::make('inicia_el')->label('Inicia')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('termina_el')->label('Termina')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('estatus')->badge()
                    ->state(fn (Dinamica $record) => $record->estatus())
                    ->formatStateUsing(fn (string $state) => ['proxima' => 'Próxima', 'activa' => 'Activa', 'terminada' => 'Terminada'][$state])
                    ->color(fn (string $state) => ['proxima' => 'info', 'activa' => 'success', 'terminada' => 'gray'][$state]),
                TextColumn::make('ganadores_count')->label('Ganadores')->numeric()->alignEnd(),
                ToggleColumn::make('visible'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDinamicas::route('/'),
            'create' => CreateDinamica::route('/create'),
            'edit' => EditDinamica::route('/{record}/edit'),
        ];
    }
}
