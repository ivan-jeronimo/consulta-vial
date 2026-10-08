<?php

namespace App\Http\Controllers\Reto;

use App\Http\Controllers\Controller;
use App\Models\Jugador;
use App\Models\Partida;
use App\Models\PreguntaReto;
use App\Services\Reto\Juego;
use App\Services\Reto\LoginFacebook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RetoController extends Controller
{
    public function __construct(private readonly Juego $juego) {}

    /** Estado para pintar la sección: quién soy, mi mejor partida del mes y si se puede jugar. */
    public function yo(LoginFacebook $login): JsonResponse
    {
        /** @var Jugador|null $jugador */
        $jugador = Auth::guard('jugador')->user();

        return response()->json([
            'login_disponible' => $login->disponible(),
            'se_puede_jugar' => PreguntaReto::aprobada()->count() >= Juego::MINIMO,
            'jugador' => $jugador ? [
                'nombre' => $jugador->nombre,
                'foto' => $jugador->urlFoto(),
                'publico' => $jugador->publico,
            ] : null,
            'mes' => $jugador ? $this->juego->resumenDelMes($jugador) : null,
        ]);
    }

    public function ranking(): JsonResponse
    {
        return response()->json([
            'mes' => now()->translatedFormat('F Y'),
            'jugadores' => $this->juego->ranking()->map(fn (object $f) => [
                'posicion' => $f->posicion,
                'nombre' => $f->nombre,
                'foto' => $f->foto ? '/storage/'.$f->foto : null,
                'puntos' => (int) $f->puntos,
                'aciertos' => (int) $f->aciertos,
            ]),
        ]);
    }

    /** Aparecer o no en el ranking con nombre y foto. */
    public function preferencias(Request $request): JsonResponse
    {
        $datos = $request->validate(['publico' => ['required', 'boolean']]);
        Auth::guard('jugador')->user()->update(['publico' => $datos['publico']]);

        return response()->json(['ok' => true]);
    }

    public function iniciar(): JsonResponse
    {
        $partida = $this->juego->iniciar(Auth::guard('jugador')->user());

        return response()->json(['partida' => $partida->id, 'pregunta' => $this->juego->preguntaActual($partida)], 201);
    }

    public function pregunta(Partida $partida): JsonResponse
    {
        $this->autorizar($partida);

        return response()->json(['pregunta' => $this->juego->preguntaActual($partida)]);
    }

    public function responder(Request $request, Partida $partida): JsonResponse
    {
        $this->autorizar($partida);
        $datos = $request->validate(['opcion' => ['present', 'nullable', 'integer', 'min:0', 'max:3']]);

        $resultado = $this->juego->responder($partida, $datos['opcion']);
        $partida->refresh();

        return response()->json([
            ...$resultado,
            'siguiente' => $this->juego->preguntaActual($partida),
            'mes' => $resultado['terminada'] ? $this->juego->resumenDelMes($partida->jugador) : null,
        ]);
    }

    /** "Borrar mis datos": cuenta, partidas y foto. También es la instrucción de borrado para Meta. */
    public function borrarCuenta(Request $request): JsonResponse
    {
        $jugador = Auth::guard('jugador')->user();
        Auth::guard('jugador')->logout();
        $jugador->borrarDatos();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    private function autorizar(Partida $partida): void
    {
        abort_unless($partida->jugador_id === Auth::guard('jugador')->id(), 404);
    }
}
