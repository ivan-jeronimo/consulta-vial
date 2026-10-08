<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Estado;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogoController extends Controller
{
    public function estados()
    {
        return Estado::orderBy('nombre')->get(['id', 'clave_inegi', 'nombre']);
    }

    public function municipios(Estado $estado)
    {
        return $estado->municipios()->orderBy('nombre')->get(['id', 'estado_id', 'clave_inegi', 'nombre']);
    }

    public function crearMunicipio(Request $request, Estado $estado)
    {
        $request->merge(['slug' => Str::slug((string) $request->input('nombre'))]);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'slug' => [Rule::unique('municipios')->where('estado_id', $estado->id)],
            'clave_inegi' => ['nullable', 'digits:3', Rule::unique('municipios')->where('estado_id', $estado->id)],
        ], ['slug.unique' => 'El municipio ya existe en este estado.']);

        return response()->json($estado->municipios()->create(Arr::except($datos, 'slug')), 201);
    }
}
