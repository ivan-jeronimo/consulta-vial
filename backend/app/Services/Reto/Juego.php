<?php

namespace App\Services\Reto;

use App\Models\Jugador;
use App\Models\Partida;
use App\Models\PreguntaReto;
use App\Models\RespuestaReto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reglas del reto. El servidor decide todo: qué preguntas tocan, en qué orden
 * van las opciones, cuánto tardó la respuesta y si fue correcta. El navegador
 * nunca recibe la respuesta correcta antes de contestar.
 */
class Juego
{
    public const PREGUNTAS = 10;

    public const MINIMO = 5;

    public const LIMITE_MS = 20_000;

    /** Margen por la latencia de la red antes de contar la respuesta como fuera de tiempo. */
    private const MARGEN_MS = 2_500;

    public const PUNTOS_ACIERTO = 100;

    public const BONO_RAPIDEZ = 50;

    public function iniciar(Jugador $jugador): Partida
    {
        // Se evita repetir las de su partida anterior mientras alcance el banco.
        $anteriores = collect($jugador->partidas()->latest()->value('preguntas'))->pluck('id');
        $ids = PreguntaReto::aprobada()->whereNotIn('id', $anteriores)->inRandomOrder()->limit(self::PREGUNTAS)->pluck('id');

        if ($ids->count() < self::PREGUNTAS) {
            $ids = $ids->concat(PreguntaReto::aprobada()->whereNotIn('id', $ids)->inRandomOrder()->limit(self::PREGUNTAS - $ids->count())->pluck('id'));
        }

        if ($ids->count() < self::MINIMO) {
            throw new HttpException(409, 'El reto todavía no tiene suficientes preguntas. ¡Vuelve pronto!');
        }

        $preguntas = PreguntaReto::whereIn('id', $ids)->get(['id', 'opciones'])->keyBy('id');

        return $jugador->partidas()->create([
            'preguntas' => $ids->map(fn (int $id) => ['id' => $id, 'orden' => collect(array_keys($preguntas[$id]->opciones))->shuffle()->all()])->all(),
        ])->refresh(); // trae los valores por omisión de la tabla (actual, puntos…)
    }

    /** La pregunta en turno, sin la respuesta. El reloj empieza la primera vez que se pide. */
    public function preguntaActual(Partida $partida): ?array
    {
        if ($partida->terminada_at) {
            return null;
        }

        if (! $partida->mostrada_at) {
            $partida->update(['mostrada_at' => now()]);
        }

        ['id' => $id, 'orden' => $orden] = $partida->preguntas[$partida->actual];
        $pregunta = PreguntaReto::findOrFail($id);

        return [
            'numero' => $partida->actual + 1,
            'total' => $partida->total(),
            'categoria' => $pregunta->categoria,
            'pregunta' => $pregunta->pregunta,
            'opciones' => array_map(fn (int $i) => $pregunta->opciones[$i], $orden),
            // Lo que le queda de tiempo (si recargó la página, el reloj siguió corriendo).
            'restante_ms' => max(0, self::LIMITE_MS - $this->transcurrido($partida)),
            'limite_ms' => self::LIMITE_MS,
        ];
    }

    /** @param  int|null  $opcion  índice en el orden mostrado; null = se acabó el tiempo */
    public function responder(Partida $partida, ?int $opcion): array
    {
        return DB::transaction(function () use ($partida, $opcion) {
            $partida = Partida::lockForUpdate()->findOrFail($partida->id);

            if ($partida->terminada_at) {
                throw new HttpException(409, 'Esta partida ya terminó.');
            }
            if (! $partida->mostrada_at) {
                throw new HttpException(409, 'Primero pide la pregunta.');
            }

            ['id' => $id, 'orden' => $orden] = $partida->preguntas[$partida->actual];
            $pregunta = PreguntaReto::findOrFail($id);
            $ms = $this->transcurrido($partida);
            $aTiempo = $ms <= self::LIMITE_MS + self::MARGEN_MS;

            $original = $opcion !== null && $aTiempo ? ($orden[$opcion] ?? null) : null;
            $correcta = $original !== null && $original === $pregunta->correcta;
            $puntos = $correcta ? $this->puntos($ms) : 0;

            RespuestaReto::create([
                'partida_id' => $partida->id,
                'pregunta_id' => $pregunta->id,
                'opcion' => $original,
                'correcta' => $correcta,
                'ms' => min($ms, 4_000_000_000),
                'puntos' => $puntos,
            ]);

            $partida->fill([
                'actual' => $partida->actual + 1,
                'mostrada_at' => null,
                'puntos' => $partida->puntos + $puntos,
                'aciertos' => $partida->aciertos + ($correcta ? 1 : 0),
            ]);
            if ($partida->actual >= $partida->total()) {
                $partida->terminada_at = now();
            }
            $partida->save();

            return [
                'correcta' => $correcta,
                'fuera_de_tiempo' => ! $aTiempo || $opcion === null,
                'opcion_correcta' => array_search($pregunta->correcta, $orden, true),
                'explicacion' => $pregunta->explicacion,
                'cita' => $pregunta->cita,
                'puntos' => $puntos,
                'puntos_partida' => $partida->puntos,
                'aciertos' => $partida->aciertos,
                'terminada' => (bool) $partida->terminada_at,
            ];
        });
    }

    /** 100 por acierto + hasta 50 por rapidez (todo el bono si contesta al instante, nada al límite). */
    public function puntos(int $ms): int
    {
        return self::PUNTOS_ACIERTO + (int) round(self::BONO_RAPIDEZ * max(0, 1 - $ms / self::LIMITE_MS));
    }

    /**
     * Mejor partida del mes de cada jugador que aceptó aparecer. Empates: gana
     * quien lo logró primero.
     *
     * @return Collection<int, object{jugador_id: int, nombre: string, foto: ?string, puntos: int, aciertos: int, posicion: int}>
     */
    public function ranking(?Carbon $mes = null, int $limite = 20): Collection
    {
        return $this->mejores($mes)
            ->join('jugadores', 'jugadores.id', '=', 'mejores.jugador_id')
            ->where('jugadores.publico', true)
            ->orderByDesc('mejores.puntos')->orderBy('mejores.terminada_at')
            ->limit($limite)
            ->get(['mejores.jugador_id', 'jugadores.nombre', 'jugadores.foto', 'mejores.puntos', 'mejores.aciertos'])
            ->values()
            ->map(function (object $fila, int $i) {
                $fila->posicion = $i + 1;

                return $fila;
            });
    }

    /** @return array{puntos: int, aciertos: int, posicion: int|null, partidas: int}|null */
    public function resumenDelMes(Jugador $jugador, ?Carbon $mes = null): ?array
    {
        $mejor = $this->mejores($mes)->where('mejores.jugador_id', $jugador->id)->first();

        if (! $mejor) {
            return null;
        }

        $posicion = $jugador->publico
            ? 1 + $this->mejores($mes)
                ->join('jugadores', 'jugadores.id', '=', 'mejores.jugador_id')
                ->where('jugadores.publico', true)
                ->where(fn ($q) => $q->where('mejores.puntos', '>', $mejor->puntos)
                    ->orWhere(fn ($q) => $q->where('mejores.puntos', $mejor->puntos)->where('mejores.terminada_at', '<', $mejor->terminada_at)))
                ->count()
            : null;

        [$desde, $hasta] = $this->rango($mes);

        return [
            'puntos' => (int) $mejor->puntos,
            'aciertos' => (int) $mejor->aciertos,
            'posicion' => $posicion,
            'partidas' => $jugador->partidas()->terminada()->whereBetween('terminada_at', [$desde, $hasta])->count(),
        ];
    }

    /** Subconsulta "mejores": la mejor partida terminada del mes por jugador (DISTINCT ON de Postgres). */
    private function mejores(?Carbon $mes)
    {
        [$desde, $hasta] = $this->rango($mes);

        $sub = Partida::query()
            ->selectRaw('distinct on (jugador_id) jugador_id, puntos, aciertos, terminada_at')
            ->whereBetween('terminada_at', [$desde, $hasta])
            ->orderBy('jugador_id')->orderByDesc('puntos')->orderBy('terminada_at');

        return DB::query()->fromSub($sub, 'mejores');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango(?Carbon $mes): array
    {
        $mes ??= now();

        return [$mes->copy()->startOfMonth(), $mes->copy()->endOfMonth()];
    }

    private function transcurrido(Partida $partida): int
    {
        return (int) max(0, $partida->mostrada_at->diffInMilliseconds(now()));
    }
}
