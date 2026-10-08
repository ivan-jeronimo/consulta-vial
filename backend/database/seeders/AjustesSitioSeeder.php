<?php

namespace Database\Seeders;

use App\Models\AjusteSitio;
use Illuminate\Database\Seeder;

class AjustesSitioSeeder extends Seeder
{
    /** Crea las claves que faltan sin tocar los valores ya editados en el panel. */
    public function run(): void
    {
        foreach (AjusteSitio::AJUSTES as $clave => [$etiqueta, $valor]) {
            AjusteSitio::firstOrCreate(['clave' => $clave], ['etiqueta' => $etiqueta, 'valor' => $valor]);
        }
    }
}
