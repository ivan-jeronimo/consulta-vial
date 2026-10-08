<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * El sitio de Andantes del Asfalto (contenido, redes y reto) pasó a su propio
 * proyecto y base de datos. En bases que ya tenían sus tablas, se quitan;
 * en una instalación nueva no existen y esto no hace nada.
 */
return new class extends Migration
{
    private const TABLAS = [
        'respuestas_reto', 'partidas', 'jugadores',
        'reto_fragmentos_revisados', 'preguntas_reto',
        'publicaciones_facebook', 'ganadores', 'dinamicas', 'colaboraciones',
        'videos_destacados', 'ajustes_sitio',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: los datos viven ahora en el proyecto andantes.
    }
};
