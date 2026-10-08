<?php

namespace App\Services\Ingesta;

/**
 * Divide documentos sin artículos (manuales, guías de examen, circulares) por
 * encabezados y, dentro de cada sección, en fragmentos de ~1200 caracteres
 * formados por párrafos completos. Cada fragmento lleva su tema (capitulo) y
 * subtema (seccion) para la búsqueda y la cita.
 */
class SegmentadorSecciones
{
    // Encabezados de primer nivel: "TEMA 3", "Unidad II", "Capítulo 1", "3. Señales…",
    // "I.-CONOCIMIENTOS GENERALES", "II. SEÑALES DE TRÁNSITO"
    private const RE_MAYOR = '/^(?:CAP[ÍI]TULO|TEMA|UNIDAD|M[ÓO]DULO|PARTE|LECCI[ÓO]N|BLOQUE)\b|^\d+\.?\s+\p{Lu}|^[IVXLC]+\s*[\.\-–)]+\s*\p{Lu}/u';

    // Subtemas numerados: "2.1 Señales preventivas", "3.2.4. Rebase"
    private const RE_NUMERADO = '/^\d+(?:\.\d+)+\.?\s+\p{Lu}/u';

    // Elementos de lista: viñetas y preguntas o puntos numerados ("12. ¿Qué indica…?").
    private const RE_ELEMENTO = '/^(?:'.NormalizadorTexto::VINETA.'|\d+[.)]\s)/u';

    public function __construct(
        private int $objetivo = 1200,
        private int $maximo = 2000,
    ) {}

    /**
     * En un documento escrito casi todo en MAYÚSCULAS (como algunas guías de
     * examen) las mayúsculas no distinguen un título: solo cuentan los
     * encabezados numerados o con palabra clave.
     */
    private bool $mayusculasSonTitulo = true;

    /** @return list<ChunkArticulo> */
    public function segmentar(string $texto): array
    {
        $lineas = array_filter(array_map('trim', explode("\n", $texto)), fn ($l) => mb_strlen($l) > 20);
        $enMayusculas = count(array_filter($lineas, fn ($l) => $this->esMayusculas($l)));
        $this->mayusculasSonTitulo = ! $lineas || $enMayusculas / count($lineas) < 0.5;

        $chunks = [];
        $capitulo = null;
        $seccion = null;
        $buffer = [];
        $longitud = 0;
        $enElemento = false; // el buffer es una viñeta o pregunta, no prosa

        $emitir = function () use (&$chunks, &$buffer, &$longitud, &$capitulo, &$seccion) {
            $contenido = trim(implode("\n", $buffer));

            if ($contenido !== '') {
                $chunks[] = new ChunkArticulo(
                    orden: count($chunks),
                    contenido: $contenido,
                    capitulo: $capitulo,
                    seccion: $seccion,
                );
            }

            $buffer = [];
            $longitud = 0;
        };

        foreach (preg_split("/\n+/", $texto) as $parrafo) {
            $parrafo = trim($parrafo);

            if ($parrafo === '') {
                continue;
            }

            if ($this->esEncabezado($parrafo)) {
                $emitir();
                $enElemento = false;

                $esTema = (preg_match(self::RE_MAYOR, $parrafo) && ! preg_match(self::RE_NUMERADO, $parrafo))
                    || ($this->mayusculasSonTitulo && $this->esMayusculas($parrafo));

                if ($esTema) {
                    $capitulo = $this->limpiar($parrafo);
                    $seccion = null;
                } else {
                    $seccion = $this->limpiar($parrafo);
                }

                continue;
            }

            // Continuación de una frase cortada por el PDF (renglón sin viñeta tras
            // una línea que no terminó en puntuación): se une con un espacio.
            $ultimo = array_key_last($buffer);
            if ($ultimo !== null
                && ! preg_match('/[.;:?!]$/u', $buffer[$ultimo])
                && ! preg_match('/^(?:'.NormalizadorTexto::VINETA.'|\d+\.\s|[a-zñ]\)|[IVXLC]+\.)/u', $parrafo)
                && mb_strlen($buffer[$ultimo]) + mb_strlen($parrafo) <= $this->maximo) {
                $buffer[$ultimo] .= ' '.$parrafo;
                $longitud += mb_strlen($parrafo) + 1;

                continue;
            }

            // Cada viñeta o pregunta numerada es una regla independiente: va en su
            // propio apartado, para que el fundamento muestre solo la que responde.
            if (preg_match(self::RE_ELEMENTO, $parrafo)) {
                $emitir();
                $enElemento = true;
                $buffer[] = $parrafo;
                $longitud = mb_strlen($parrafo);

                continue;
            }

            // Opciones de una pregunta de examen ("a) …", "b) …"): con su pregunta.
            if ($enElemento && preg_match('/^[a-zñ]\)\s/u', $parrafo)) {
                $buffer[] = $parrafo;
                $longitud += mb_strlen($parrafo);

                continue;
            }

            // Prosa normal después de una lista: cierra el último elemento.
            if ($enElemento) {
                $emitir();
                $enElemento = false;
            }

            foreach ($this->partirLargo($parrafo) as $pedazo) {
                if ($longitud > 0 && $longitud + mb_strlen($pedazo) > $this->objetivo) {
                    $emitir();
                }

                $buffer[] = $pedazo;
                $longitud += mb_strlen($pedazo);
            }
        }

        $emitir();

        return $chunks;
    }

    private function esEncabezado(string $linea): bool
    {
        // Un encabezado es corto, no es un elemento de lista y no termina como
        // oración, lista o pregunta.
        if (mb_strlen($linea) > 100 || preg_match('/[.,;:?¿!]$/u', $linea) || preg_match('/^'.NormalizadorTexto::VINETA.'/u', $linea)) {
            return false;
        }

        return ($this->mayusculasSonTitulo && $this->esMayusculas($linea))
            || preg_match(self::RE_NUMERADO, $linea)
            || (preg_match(self::RE_MAYOR, $linea) && mb_strlen($linea) <= 80);
    }

    private function esMayusculas(string $linea): bool
    {
        return preg_match('/\p{Lu}{3}/u', $linea) && mb_strtoupper($linea) === $linea;
    }

    /** Un párrafo más largo que el máximo se corta por oraciones. @return list<string> */
    private function partirLargo(string $parrafo): array
    {
        if (mb_strlen($parrafo) <= $this->maximo) {
            return [$parrafo];
        }

        $partes = [];
        $actual = '';

        foreach (preg_split('/(?<=[.;])\s+/u', $parrafo) as $oracion) {
            if ($actual !== '' && mb_strlen($actual) + mb_strlen($oracion) > $this->objetivo) {
                $partes[] = $actual;
                $actual = '';
            }

            $actual = $actual === '' ? $oracion : "{$actual} {$oracion}";
        }

        if ($actual !== '') {
            $partes[] = $actual;
        }

        // Una sola "oración" gigantesca (tabla aplanada, etc.): corte en duro.
        return array_merge(...array_map(fn (string $p) => mb_str_split($p, $this->maximo), $partes));
    }

    private function limpiar(string $linea): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $linea), " .-–—"), 0, 255);
    }
}
