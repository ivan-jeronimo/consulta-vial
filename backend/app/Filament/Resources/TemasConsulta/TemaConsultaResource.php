<?php

namespace App\Filament\Resources\TemasConsulta;

use App\Filament\Resources\TemasConsulta\Pages\EditTemaConsulta;
use App\Filament\Resources\TemasConsulta\Pages\ListTemasConsulta;
use App\Filament\Resources\TemasConsulta\RelationManagers\ConsultasRelationManager;
use App\Models\TemaConsulta;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class TemaConsultaResource extends Resource
{
    protected static ?string $model = TemaConsulta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Métricas';

    protected static ?string $modelLabel = 'tema';

    protected static ?string $pluralModelLabel = 'temas';

    protected static ?string $recordTitleAttribute = 'etiqueta';

    protected static ?string $slug = 'temas';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('etiqueta')
                ->label('Nombre del tema')
                ->helperText('Se crea con la primera pregunta del tema; puedes reescribirlo para que se lea mejor en los reportes.')
                ->required()
                ->maxLength(500)
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('consultas as total')
                ->withCount(['consultas as sin_cobertura' => fn (Builder $c) => $c->where('cubierto', false)])
                ->withMax('consultas as ultima', 'created_at'))
            ->defaultSort('total', 'desc')
            ->columns([
                TextColumn::make('etiqueta')->label('Tema')->wrap()->searchable(),
                TextColumn::make('total')->label('Consultas')->numeric()->sortable()->alignEnd(),
                TextColumn::make('sin_cobertura')
                    ->label('Sin cobertura')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),
                TextColumn::make('ultima')->label('Última consulta')->since()->sortable(),
            ])
            ->recordActions([EditAction::make()->label('Ver')])
            ->toolbarActions([
                // La agrupación automática puede separar dos formas de preguntar lo mismo.
                BulkAction::make('unir')
                    ->label('Unir en un solo tema')
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->requiresConfirmation()
                    ->modalDescription('Las consultas de los temas seleccionados pasarán al tema con más consultas, y los demás se eliminarán.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $temas) {
                        if ($temas->count() < 2) {
                            Notification::make()->title('Selecciona al menos dos temas')->warning()->send();

                            return;
                        }

                        $destino = $temas->sortByDesc('total')->first();

                        DB::transaction(function () use ($temas, $destino) {
                            $otros = $temas->except($destino->getKey());
                            DB::table('consultas')->whereIn('tema_id', $otros->modelKeys())->update(['tema_id' => $destino->getKey()]);
                            TemaConsulta::whereKey($otros->modelKeys())->delete();
                        });

                        Notification::make()->title("Temas unidos en «{$destino->etiqueta}»")->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [ConsultasRelationManager::class];
    }

    public static function canCreate(): bool
    {
        return false; // los temas se crean solos a partir de las consultas
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTemasConsulta::route('/'),
            'edit' => EditTemaConsulta::route('/{record}/edit'),
        ];
    }
}
