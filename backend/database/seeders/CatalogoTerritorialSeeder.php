<?php

namespace Database\Seeders;

use App\Models\Estado;
use App\Models\Municipio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogoTerritorialSeeder extends Seeder
{
    private const ESTADOS = [
        '01' => 'Aguascalientes', '02' => 'Baja California', '03' => 'Baja California Sur',
        '04' => 'Campeche', '05' => 'Coahuila de Zaragoza', '06' => 'Colima',
        '07' => 'Chiapas', '08' => 'Chihuahua', '09' => 'Ciudad de México',
        '10' => 'Durango', '11' => 'Guanajuato', '12' => 'Guerrero',
        '13' => 'Hidalgo', '14' => 'Jalisco', '15' => 'México',
        '16' => 'Michoacán de Ocampo', '17' => 'Morelos', '18' => 'Nayarit',
        '19' => 'Nuevo León', '20' => 'Oaxaca', '21' => 'Puebla',
        '22' => 'Querétaro', '23' => 'Quintana Roo', '24' => 'San Luis Potosí',
        '25' => 'Sinaloa', '26' => 'Sonora', '27' => 'Tabasco',
        '28' => 'Tamaulipas', '29' => 'Tlaxcala', '30' => 'Veracruz de Ignacio de la Llave',
        '31' => 'Yucatán', '32' => 'Zacatecas',
    ];

    // Municipios de ejemplo; el resto se da de alta desde el panel.
    private const MUNICIPIOS = [
        '20' => ['067' => 'Oaxaca de Juárez', '385' => 'Santa Cruz Xoxocotlán', '157' => 'San Jacinto Amilpas'],
    ];

    public function run(): void
    {
        foreach (self::ESTADOS as $clave => $nombre) {
            Estado::updateOrCreate(
                ['clave_inegi' => $clave],
                ['nombre' => $nombre, 'slug' => Str::slug($nombre)],
            );
        }

        foreach (self::MUNICIPIOS as $claveEstado => $municipios) {
            $estado = Estado::where('clave_inegi', $claveEstado)->firstOrFail();

            foreach ($municipios as $clave => $nombre) {
                Municipio::updateOrCreate(
                    ['estado_id' => $estado->id, 'clave_inegi' => $clave],
                    ['nombre' => $nombre, 'slug' => Str::slug($nombre)],
                );
            }
        }
    }
}
