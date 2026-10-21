<?php

namespace App\Filament\Resources\DocumentoReglamentos\Schemas;

use App\Enums\EstatusProcesamiento;
use App\Enums\EstatusVigencia;
use App\Enums\NivelJurisdiccion;
use App\Enums\TipoDocumento;
use App\Models\DocumentoReglamento;
use App\Models\Municipio;
use App\Services\Ingesta\RegistroReglamentos;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DocumentoReglamentoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Documento')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('titulo')
                            ->label('Título')
                            ->placeholder('Ej. Manual del Conductor del Estado de Oaxaca 2023')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('emisor')
                            ->label('Quién lo emite')
                            ->placeholder('Ej. Secretaría de Movilidad del Estado de Oaxaca (SEMOVI)')
                            ->maxLength(255)
                            ->helperText('La dependencia o autoridad que lo expide. Va en la cita de las fuentes (chat y pregunta diaria).')
                            ->columnSpanFull(),

                        TextInput::make('url_publica')
                            ->label('Enlace público')
                            ->placeholder('https://www.periodicooficial.oaxaca.gob.mx/…')
                            ->url()
                            ->maxLength(2048)
                            ->required()
                            ->suffixIcon('heroicon-m-arrow-top-right-on-square')
                            ->helperText('Dónde puede leerlo cualquiera: Periódico Oficial o sitio del gobierno. El chat enlaza aquí desde cada artículo que cita.')
                            ->columnSpanFull(),

                        TextInput::make('publicado_en')
                            ->label('Publicado oficialmente en')
                            ->placeholder('Ej. Periódico Oficial del Estado de Oaxaca, 30/04/2026')
                            ->maxLength(255)
                            ->helperText('Opcional. Dónde y cuándo se publicó de forma oficial.')
                            ->columnSpanFull(),

                        ToggleButtons::make('tipo')
                            ->label('Tipo de documento')
                            ->options(TipoDocumento::class)
                            ->default(TipoDocumento::Reglamento)
                            ->inline()
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get) => match (self::tipo($get)) {
                                TipoDocumento::Manual, TipoDocumento::Otro => 'Se divide por temas y párrafos. El bot lo usa como material de apoyo: si contradice a un reglamento, prevalece el reglamento.',
                                default => 'Se divide por artículos, fracciones e incisos. El bot lo trata como norma obligatoria.',
                            })
                            ->columnSpanFull(),

                        ToggleButtons::make('nivel')
                            ->label('Nivel de jurisdicción')
                            ->options(NivelJurisdiccion::class)
                            ->inline()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('estado_id', null);
                                $set('municipio_id', null);
                                $set('reemplaza_a_id', null);
                            })
                            ->columnSpanFull(),

                        Select::make('estado_id')
                            ->label('Estado')
                            ->relationship('estado', 'nombre')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('municipio_id', null);
                                $set('reemplaza_a_id', null);
                            })
                            ->visible(fn (Get $get) => in_array(self::nivel($get), [NivelJurisdiccion::Estatal, NivelJurisdiccion::Municipal], true))
                            ->required(fn (Get $get) => in_array(self::nivel($get), [NivelJurisdiccion::Estatal, NivelJurisdiccion::Municipal], true)),

                        Select::make('municipio_id')
                            ->label('Municipio')
                            ->relationship(
                                'municipio',
                                'nombre',
                                fn (Builder $query, Get $get) => $query->where('estado_id', $get('estado_id'))->orderBy('nombre'),
                            )
                            ->searchable()
                            ->preload()
                            ->live()
                            ->disabled(fn (Get $get) => blank($get('estado_id')))
                            ->helperText(fn (Get $get) => blank($get('estado_id')) ? 'Primero elige el estado.' : null)
                            ->createOptionForm([
                                TextInput::make('nombre')->required()->maxLength(150),
                                TextInput::make('clave_inegi')->label('Clave INEGI')->length(3)->numeric(),
                            ])
                            ->createOptionUsing(fn (array $data, Get $get) => Municipio::create([
                                ...$data,
                                'estado_id' => $get('estado_id'),
                            ])->getKey())
                            ->visible(fn (Get $get) => self::nivel($get) === NivelJurisdiccion::Municipal)
                            ->required(fn (Get $get) => self::nivel($get) === NivelJurisdiccion::Municipal),
                    ]),

                Section::make('Vigencia')
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('anio')
                            ->label('Año')
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue(2100)
                            ->default((int) date('Y'))
                            ->required(),

                        DatePicker::make('fecha_publicacion')
                            ->label('Fecha de publicación'),

                        ToggleButtons::make('estatus_vigencia')
                            ->label('Estatus')
                            ->options(EstatusVigencia::class)
                            ->default(EstatusVigencia::Vigente)
                            ->inline()
                            ->required(),

                        Select::make('reemplaza_a_id')
                            ->label('Reemplaza a')
                            ->relationship(
                                'reemplazaA',
                                'titulo',
                                fn (Builder $query, Get $get, ?DocumentoReglamento $record) => $query
                                    ->where('nivel', self::nivel($get)?->value)
                                    ->where('estado_id', $get('estado_id'))
                                    ->where('municipio_id', $get('municipio_id'))
                                    ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                    ->latest('anio'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (DocumentoReglamento $r) => "{$r->titulo} ({$r->anio})")
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get) => filled(self::nivel($get)))
                            ->helperText('Cuando este documento termine de procesarse, el seleccionado se marcará como derogado.'),
                    ]),

                Section::make('Contenido')
                    ->description('PDF con texto seleccionable, DOCX, TXT o Markdown (máx. 50 MB). Los PDF escaneados requieren OCR previo.')
                    ->columnSpanFull()
                    ->visibleOn('create')
                    ->schema([
                        ToggleButtons::make('fuente')
                            ->hiddenLabel()
                            ->options(['archivo' => 'Subir archivo', 'texto' => 'Pegar texto'])
                            ->icons(['archivo' => 'heroicon-o-arrow-up-tray', 'texto' => 'heroicon-o-clipboard-document'])
                            ->default('archivo')
                            ->inline()
                            ->live()
                            ->dehydrated(false),

                        FileUpload::make('archivo_path')
                            ->hiddenLabel()
                            ->disk(config('rag.ingesta.disk'))
                            ->directory(config('rag.ingesta.directorio'))
                            ->visibility('private')
                            ->acceptedFileTypes(array_values(RegistroReglamentos::MIME_POR_EXTENSION))
                            ->maxSize(51200)
                            ->storeFileNamesIn('archivo_nombre_original')
                            // Conserva la extensión original: de ella se deriva el MIME canónico.
                            ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::uuid().'.'.strtolower($file->getClientOriginalExtension()))
                            ->visible(fn (Get $get) => $get('fuente') !== 'texto')
                            ->required(fn (Get $get) => $get('fuente') !== 'texto'),

                        // Destino de storeFileNamesIn(); sin campo propio Filament lo descarta.
                        Hidden::make('archivo_nombre_original'),

                        Textarea::make('texto')
                            ->hiddenLabel()
                            ->placeholder("TÍTULO PRIMERO\nCAPÍTULO I\nARTÍCULO 1.- ...")
                            ->rows(16)
                            ->minLength(50)
                            ->visible(fn (Get $get) => $get('fuente') === 'texto')
                            ->required(fn (Get $get) => $get('fuente') === 'texto'),
                    ]),

                Section::make('Procesamiento')
                    ->columnSpanFull()
                    ->columns(4)
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('estatus_procesamiento')->label('Estatus')->badge(),
                        TextEntry::make('chunks_count')->label('Fragmentos'),
                        TextEntry::make('embedding_modelo')->label('Modelo')->placeholder('—'),
                        TextEntry::make('procesado_at')->label('Procesado')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('archivo_nombre_original')->label('Archivo')->placeholder('Texto pegado')->columnSpan(2),
                        TextEntry::make('archivo_sha256')->label('SHA-256')->limit(16)->tooltip(fn ($state) => $state)->columnSpan(2),
                        TextEntry::make('error_mensaje')
                            ->label('Error')
                            ->color('danger')
                            ->columnSpanFull()
                            ->visible(fn (?DocumentoReglamento $record) => $record?->estatus_procesamiento === EstatusProcesamiento::Error),
                    ]),
            ]);
    }

    private static function tipo(Get $get): ?TipoDocumento
    {
        $tipo = $get('tipo');

        return $tipo instanceof TipoDocumento ? $tipo : TipoDocumento::tryFrom((string) $tipo);
    }

    private static function nivel(Get $get): ?NivelJurisdiccion
    {
        $nivel = $get('nivel');

        return $nivel instanceof NivelJurisdiccion ? $nivel : NivelJurisdiccion::tryFrom((string) $nivel);
    }
}
