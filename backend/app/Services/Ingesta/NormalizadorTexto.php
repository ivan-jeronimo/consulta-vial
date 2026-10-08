<?php

namespace App\Services\Ingesta;

class NormalizadorTexto
{
    // Líneas que inician un elemento estructural y nunca deben pegarse a la anterior.
    private const INICIO_ESTRUCTURAL = '/^(?:T[ÍI]TULO|CAP[ÍI]TULO|SECCI[ÓO]N|ART[ÍI]CULO|Art[íi]culo|Art\.|TRANSITORIOS?|(?:PRIMERO|SEGUNDO|TERCERO|CUARTO|QUINTO|SEXTO|S[ÉE]PTIMO|OCTAVO|NOVENO|D[ÉE]CIMO|[ÚU]NICO)\b|[IVXLC]+\s*[\.\-–)]|[a-zñ]\)|\d+\.\s|'.self::VINETA.')/u';

    // Viñetas de listas (incluida la de Symbol/Wingdings que Word exporta como U+F0B7).
    public const VINETA = '[●•▪■◦‣○\x{F0A7}\x{F0B7}]';

    public function normalizar(string $texto): string
    {
        $texto = $this->unificarCaracteres($texto);
        $lineas = explode("\n", $texto);
        $lineas = $this->quitarRuidoDePagina($lineas);
        $lineas = $this->unirMarcadoresSueltos($lineas);
        $texto = $this->unirLineasPartidas($lineas);

        return trim(preg_replace("/\n{3,}/", "\n\n", $texto));
    }

    /**
     * pdftotext suele dejar el número de fracción o inciso solo en su línea
     * ("I." y, tras una línea vacía, "Circular sin licencia…"); se reúnen para
     * que el segmentador reconozca "I. Circular sin licencia…".
     */
    private function unirMarcadoresSueltos(array $lineas): array
    {
        $salida = [];
        $pendiente = null;

        foreach ($lineas as $linea) {
            if ($pendiente !== null) {
                if ($linea === '') {
                    continue;
                }

                $linea = "{$pendiente} {$linea}";
                $pendiente = null;
            }

            if (preg_match('/^(?:[IVXLC]+|[a-zñ])\s*[\.\-–)]+$/u', $linea)) {
                $pendiente = $linea;

                continue;
            }

            $salida[] = $linea;
        }

        if ($pendiente !== null) {
            $salida[] = $pendiente;
        }

        return $salida;
    }

    private function unificarCaracteres(string $texto): string
    {
        if (class_exists(\Normalizer::class)) {
            $texto = \Normalizer::normalize($texto, \Normalizer::FORM_C) ?: $texto;
        }

        $texto = str_replace(["\r\n", "\r", "\u{00A0}", "\u{00AD}"], ["\n", "\n", ' ', ''], $texto);
        $texto = str_replace(['“', '”', '‘', '’'], ['"', '"', "'", "'"], $texto);
        $texto = preg_replace('/[ \t]+/u', ' ', $texto);

        // Pies de página tipo "Página 38 | 65" o "Pág. 3 de 40", aunque el PDF
        // los haya pegado al final de una línea de texto.
        $texto = preg_replace('/ ?\bP[áa]g(?:ina|\.)? ?\d{1,4} ?(?:\||de|\/) ?\d{1,4}\b/iu', "\n", $texto);

        // Palabras cortadas con guion al final de línea: "circula-\nción" → "circulación"
        return preg_replace('/(\p{Ll})-\n(\p{Ll})/u', '$1$2', $texto);
    }

    /**
     * Quita números de página y encabezados/pies que se repiten en cada hoja
     * (nombre del periódico oficial, fecha de última reforma, etc.).
     */
    private function quitarRuidoDePagina(array $lineas): array
    {
        $lineas = array_map('trim', $lineas);
        $paginas = count(array_keys($lineas, "\f", true)) + 1;

        // Solo hay "encabezados repetidos" si el origen tenía páginas (PDF).
        $frecuencias = $paginas > 1
            ? array_count_values(array_filter($lineas, fn ($l) => $l !== '' && mb_strlen($l) <= 150))
            : [];

        $umbral = max(3, (int) ceil($paginas * 0.5));

        return array_values(array_filter($lineas, function (string $linea) use ($frecuencias, $umbral) {
            if ($linea === "\f") {
                return false;
            }

            // Índice: su encabezado y renglones como "CONOCIMIENTOS GENERALES ………… 3"
            if (preg_match('/(?:\.{5,}|…{2,}|(?:\. ){5,})|^(?:[ÍI]NDICE(?:\s+GENERAL)?|CONTENIDO)$/iu', $linea)) {
                return false;
            }

            if (preg_match('/^(?:p[áa]g(?:ina)?\.?\s*)?\d{1,4}(?:\s*(?:de|\/)\s*\d{1,4})?$/iu', $linea)) {
                return false;
            }

            if (preg_match(self::INICIO_ESTRUCTURAL, $linea)) {
                return true;
            }

            return ($frecuencias[$linea] ?? 0) < $umbral;
        }));
    }

    /**
     * Los PDF cortan los párrafos en líneas físicas; se reconstruyen uniendo una
     * línea con la siguiente salvo que la siguiente inicie un elemento estructural
     * o que se pase de un título en MAYÚSCULAS a texto normal (o al revés): así
     * "SEÑALES DE TRÁNSITO" no se funde con el párrafo que lo sigue, pero
     * "TÍTULO PRIMERO" + "DISPOSICIONES GENERALES" sí forman un solo título.
     */
    private function unirLineasPartidas(array $lineas): string
    {
        $salida = '';
        $anterior = null;

        foreach ($lineas as $linea) {
            if ($linea === '') {
                $salida .= "\n\n";
                $anterior = null;

                continue;
            }

            // De título a texto siempre se corta. De texto a título solo si el texto
            // anterior terminó su frase: si no, es un renglón partido en MAYÚSCULAS
            // ("…EL CARRIL DE EXTREMA IZQUIERDA ES EL" / "INDICADO PARA REBASAR").
            $cambiaATexto = $anterior !== null && $this->esTitulo($anterior) && ! $this->esTitulo($linea);
            $cambiaATitulo = $anterior !== null && ! $this->esTitulo($anterior) && $this->esTitulo($linea)
                && preg_match('/[.;:]$/u', $anterior);

            $nuevaLinea = $salida === ''
                || str_ends_with($salida, "\n")
                || preg_match(self::INICIO_ESTRUCTURAL, $linea)
                || $cambiaATexto
                || $cambiaATitulo;

            $salida .= ($nuevaLinea ? ($salida === '' || str_ends_with($salida, "\n") ? '' : "\n") : ' ').$linea;
            $anterior = $linea;
        }

        return $salida;
    }

    /** Títulos en MAYÚSCULAS o subtemas numerados de manuales ("3.1 Señales restrictivas"). */
    private function esTitulo(string $linea): bool
    {
        if (mb_strlen($linea) > 100 || preg_match('/[.,;:?!]$/u', $linea)) {
            return false;
        }

        return (preg_match('/\p{Lu}{3}/u', $linea) && mb_strtoupper($linea) === $linea)
            || (mb_strlen($linea) <= 80 && preg_match('/^\d+(?:\.\d+)+\.?\s+\p{Lu}/u', $linea));
    }
}
