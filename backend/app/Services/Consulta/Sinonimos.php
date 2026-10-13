<?php

namespace App\Services\Consulta;

/**
 * Puente entre cómo pregunta la gente y cómo están escritos los reglamentos:
 * "estoy aprendiendo a manejar" no comparte ninguna palabra con "durante la
 * enseñanza los conductores…", ni "celular" con "teléfono móvil". A la pregunta
 * se le agregan los términos del reglamento antes de buscar (texto y vector).
 *
 * Solo términos de búsqueda: el modelo recibe la pregunta original.
 */
class Sinonimos
{
    /** Patrón (sin acentos, minúsculas) => términos con que lo dice el reglamento. */
    private const TERMINOS = [
        '/\baprend(er|o|e|iendo|iz|izaje)\b|\bpractica(r|ndo)\b/' => 'enseñanza aprendizaje escuela de manejo autoescuela conductores',
        '/\b(manej|conduc)(ar|ir|o|a|iendo)\b/' => 'conducir conductor',
        '/\b(celular|cel|telefono|whatsapp|mensajes?)\b/' => 'teléfono celular dispositivo móvil',
        '/\bmultas?\b|\bme (multan|infraccionan)\b/' => 'multa infracción sanción',
        '/\bmotos?\b/' => 'motocicleta motociclista',
        '/\bbicis?\b/' => 'bicicleta ciclista',
        '/\bbanquetas?\b/' => 'banqueta acera',
        '/\b(alcohol|borracho|tomado|ebrio|copas|cerveza|alcoholimetro)\b/' => 'estado de ebriedad alcohol aliento alcohólico',
        '/\b(grua|corralon)\b/' => 'grúa depósito vehicular arrastre',
        '/\b(ninos?|nina|menores?|bebe|hijos?)\b/' => 'menores de edad niños',
        '/\bestacion(ar|arme|arse|o)\b/' => 'estacionamiento estacionar',
        '/\b(cochera|garage|garaje)\b/' => 'cochera entrada salida de vehículos',
        '/\b(auriculares?|audifonos?|musica|headphones?)\b/' => 'audífonos aparato reproductor de música',
        '/\b(perros?|perritos?|gatos?|mascotas?)\b/' => 'mascotas animales canastillas',
        '/\bfiltr(ar|an|o|ando)\b|\bentre (los )?(carros|coches|autos|carriles)\b/' => 'circular entre carriles centro del carril',
        '/\brebas(e|es|ar|o|an)\b|\badelantar\b/' => 'rebasar rebase por la izquierda',
    ];

    public static function expandir(string $pregunta): string
    {
        $normal = mb_strtolower(self::sinAcentos($pregunta));
        $extra = [];

        foreach (self::TERMINOS as $patron => $terminos) {
            if (preg_match($patron, $normal)) {
                $extra[] = $terminos;
            }
        }

        return $extra ? $pregunta.' ('.implode(', ', $extra).')' : $pregunta;
    }

    private static function sinAcentos(string $texto): string
    {
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n']);
    }
}
