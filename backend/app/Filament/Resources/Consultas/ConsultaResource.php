<?php

namespace App\Filament\Resources\Consultas;

use App\Filament\Resources\Consultas\Pages\ListConsultas;
use App\Models\Consulta;
use App\Models\ReglamentoArticulo;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Historial anónimo de preguntas (solo lectura). */
class ConsultaResource extends Resource
{
    protected static ?string $model = Consulta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Métricas';

    protected static ?string $modelLabel = 'consulta';

    protected static ?string $pluralModelLabel = 'consultas';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('pregunta')->columnSpanFull(),
            TextEntry::make('tema.etiqueta')->label('Tema')->placeholder('—')->columnSpanFull(),
            TextEntry::make('ubicacion')->label('Ubicación')->state(fn (Consulta $c) => self::ubicacion($c)),
            TextEntry::make('resultado')->label('Resultado')->badge()->state(fn (Consulta $c) => self::resultado($c))->color(fn ($state) => self::colorResultado($state)),
            TextEntry::make('valoracion')->label('Valoración')->state(fn (Consulta $c) => self::valoracion($c))->placeholder('Sin valorar'),
            TextEntry::make('modelo')->placeholder('—'),
            TextEntry::make('latencia_ms')->label('Tiempo de respuesta')->formatStateUsing(fn ($state) => $state ? number_format($state / 1000, 1).' s' : '—'),
            TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
            TextEntry::make('fuentes')
                ->label('Fundamento citado')
                ->columnSpanFull()
                ->listWithLineBreaks()
                ->placeholder('Ninguno')
                ->state(fn (Consulta $c) => ReglamentoArticulo::with('documento')
                    ->findMany($c->fuentes ?? [])
                    ->map(fn (ReglamentoArticulo $a) => collect([
                        $a->documento->titulo,
                        $a->articulo ? "Art. {$a->articulo}" : $a->capitulo,
                        $a->fraccion ? "fracc. {$a->fraccion}" : null,
                        $a->inciso ? "inciso {$a->inciso})" : null,
                    ])->filter()->implode(' · '))
                    ->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['estado', 'municipio', 'tema']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m H:i')->sortable(),
                TextColumn::make('pregunta')->wrap()->limit(160)->searchable(),
                TextColumn::make('ubicacion')->label('Ubicación')->state(fn (Consulta $c) => self::ubicacion($c))->toggleable(),
                TextColumn::make('resultado')
                    ->label('Resultado')
                    ->badge()
                    ->state(fn (Consulta $c) => self::resultado($c))
                    ->color(fn ($state) => self::colorResultado($state)),
                TextColumn::make('valoracion')->label('👍/👎')->state(fn (Consulta $c) => self::valoracion($c))->alignCenter(),
                TextColumn::make('tema.etiqueta')->label('Tema')->limit(50)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latencia_ms')
                    ->label('Tiempo')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1000, 1).' s' : '—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('cubierto')
                    ->label('Cobertura')
                    ->trueLabel('Con fundamento')
                    ->falseLabel('Sin cobertura'),
                SelectFilter::make('valoracion')
                    ->label('Valoración')
                    ->options([1 => '👍 Útil', -1 => '👎 No útil']),
                SelectFilter::make('estado_id')->label('Estado')->relationship('estado', 'nombre')->searchable()->preload(),
                SelectFilter::make('municipio_id')->label('Municipio')->relationship('municipio', 'nombre')->searchable()->preload(),
            ])
            ->recordActions([ViewAction::make()->modalWidth('3xl')]);
    }

    public static function ubicacion(Consulta $c): string
    {
        return collect([$c->municipio?->nombre, $c->estado?->nombre])->filter()->implode(', ') ?: '—';
    }

    public static function resultado(Consulta $c): string
    {
        return match (true) {
            $c->cubierto === false => 'Sin cobertura',
            $c->respondida => 'Respondida',
            default => 'Solo artículos',
        };
    }

    public static function colorResultado(string $resultado): string
    {
        return match ($resultado) {
            'Respondida' => 'success',
            'Sin cobertura' => 'warning',
            default => 'gray',
        };
    }

    public static function valoracion(Consulta $c): ?string
    {
        return match ($c->valoracion) {
            1 => '👍',
            -1 => '👎',
            default => null,
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListConsultas::route('/')];
    }
}
