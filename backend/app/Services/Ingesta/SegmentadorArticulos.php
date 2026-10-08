<?php

namespace App\Services\Ingesta;

/**
 * Divide un reglamento mexicano normalizado en chunks conservando la ruta
 * Título > Capítulo > Sección > Artículo. Los artículos cortos quedan enteros;
 * los largos se dividen en apartados (fracción y, si la fracción es larga,
 * inciso) para que la búsqueda devuelva solo la parte que responde. Cada
 * apartado lleva como `contexto` el proemio del artículo (y el de su fracción),
 * sin el cual "b) Frente a una flecha verde…" no se entendería.
 */
class SegmentadorArticulos
{
    // Ordinal tras TÍTULO/CAPÍTULO/SECCIÓN, con o sin espacio: algunos PDF
    // pierden el espacio ("CAPÍTULOCUARTO OBLIGACIONES…").
    private const ORDINAL = '(?:[IVXLC]+\b|\d+|PRIMER[OA]|SEGUND[OA]|TERCER[OA]|CUART[OA]|QUINT[OA]|SEXT[OA]|S[ÉE]PTIM[OA]|OCTAV[OA]|NOVEN[OA]|D[ÉE]CIM[OA]|[ÚU]NIC[OA])';

    private const RE_TITULO = '/^T[ÍI]TULO\s*'.self::ORDINAL.'.*$/u';
    private const RE_CAPITULO = '/^CAP[ÍI]TULO\s*'.self::ORDINAL.'.*$/u';
    private const RE_SECCION = '/^SECCI[ÓO]N\s*'.self::ORDINAL.'.*$/u';
    private const RE_TRANSITORIOS = '/^(?:ART[ÍI]CULOS\s+)?TRANSITORIOS?\b.*$/u';

    // "ARTÍCULO 15.-", "Artículo 15 Bis.", "Art. 3o.-", "ARTÍCULO 120 TER:",
    // "ARTÍCULO175." (algunos PDF pierden el espacio tras ARTÍCULO)
    private const RE_ARTICULO = '/^(?:ART[ÍI]CULO\s*|Art[íi]culo\s+|Art\.\s*)(\d+(?:\s*[oº°])?(?:\s+(?:Bis|BIS|Ter|TER|Qu[aá]ter|QU[AÁ]TER|Quinquies|QUINQUIES))?)\s*[\.\-–—:]+\s*(.*)$/u';

    // Transitorios: "PRIMERO.-", "ÚNICO.", "Artículo Segundo.-"
    private const RE_TRANSITORIO = '/^(?:(?:ART[ÍI]CULO|Art[íi]culo)\s+)?(PRIMERO|SEGUNDO|TERCERO|CUARTO|QUINTO|SEXTO|S[ÉE]PTIMO|OCTAVO|NOVENO|D[ÉE]CIMO(?:\s+\p{L}+)?|[ÚU]NICO|Primero|Segundo|Tercero|Cuarto|Quinto|Sexto|S[ée]ptimo|Octavo|Noveno|D[ée]cimo(?:\s+\p{L}+)?|[ÚU]nico)\s*[\.\-–—:]+\s*(.*)$/u';

    private const RE_FRACCION = '/^([IVXLC]+)\s*[\.\-–)]\s+/u';

    private const RE_INCISO = '/^([a-zñ])\)\s+/u';

    // "XV. Derogada.", "III.- Se deroga." — no aportan nada a la consulta.
    private const RE_DEROGADO = '/^\s*(?:[IVXLC]+|[a-zñ])\s*[\.\-–)]+\s*(?:\(?\s*Se\s+deroga|Derogad[ao])\b[^\n]{0,80}$/iu';

    /**
     * @param  int  $maxCaracteres  Ningún chunk supera este tamaño.
     * @param  int  $minParaIncisos  Fracciones más largas se dividen por incisos.
     * @param  int  $minProemioPropio  Un proemio con al menos este texto (sin contar
     *                                 "ARTÍCULO N.") es también un apartado propio.
     */
    public function __construct(
        private int $maxCaracteres = 6000,
        private int $minParaIncisos = 700,
        private int $minProemioPropio = 80,
    ) {}

    /** @return list<ChunkArticulo> */
    public function segmentar(string $texto): array
    {
        $ctx = ['titulo' => null, 'capitulo' => null, 'seccion' => null];
        $enTransitorios = false;
        $articuloActual = null; // ['numero' => string, 'lineas' => list<string>, 'ctx' => array]
        $preambulo = [];
        $chunks = [];

        $cerrarArticulo = function () use (&$articuloActual, &$chunks) {
            if ($articuloActual !== null) {
                array_push($chunks, ...$this->construirChunks($articuloActual, count($chunks)));
                $articuloActual = null;
            }
        };

        foreach (explode("\n", $texto) as $linea) {
            $linea = trim($linea);

            if ($linea === '') {
                if ($articuloActual !== null) {
                    $articuloActual['lineas'][] = '';
                }

                continue;
            }

            if (preg_match(self::RE_TRANSITORIOS, $linea)) {
                $cerrarArticulo();
                $enTransitorios = true;
                $ctx = ['titulo' => 'Transitorios', 'capitulo' => null, 'seccion' => null];

                continue;
            }

            if (preg_match(self::RE_TITULO, $linea)) {
                $cerrarArticulo();
                $ctx = ['titulo' => $this->limpiarEncabezado($linea), 'capitulo' => null, 'seccion' => null];

                continue;
            }

            if (preg_match(self::RE_CAPITULO, $linea)) {
                $cerrarArticulo();
                $ctx['capitulo'] = $this->limpiarEncabezado($linea);
                $ctx['seccion'] = null;

                continue;
            }

            if (preg_match(self::RE_SECCION, $linea)) {
                $cerrarArticulo();
                $ctx['seccion'] = $this->limpiarEncabezado($linea);

                continue;
            }

            $esArticulo = preg_match(self::RE_ARTICULO, $linea, $m)
                || ($enTransitorios && preg_match(self::RE_TRANSITORIO, $linea, $m));

            if ($esArticulo) {
                $cerrarArticulo();
                $articuloActual = [
                    'numero' => $this->normalizarNumero($m[1]),
                    'lineas' => [$linea],
                    'ctx' => $ctx,
                ];

                continue;
            }

            if ($articuloActual !== null) {
                $articuloActual['lineas'][] = $linea;
            } elseif (empty($chunks)) {
                $preambulo[] = $linea; // exposición de motivos / considerandos
            }
        }

        $cerrarArticulo();

        // Documento sin artículos reconocibles (circular, acuerdo, etc.):
        // se divide por secciones y párrafos para no perder el contenido.
        if (empty($chunks)) {
            return (new SegmentadorSecciones)->segmentar($texto);
        }

        return $chunks;
    }

    /** @return list<ChunkArticulo> */
    private function construirChunks(array $articulo, int $ordenInicial): array
    {
        $contenido = trim(preg_replace("/\n{2,}/", "\n", implode("\n", $articulo['lineas'])));
        $ctx = $articulo['ctx'];
        $chunks = [];

        $agregar = function (string $texto, ?string $contexto = null, ?string $fraccion = null, ?string $inciso = null) use (&$chunks, $ctx, $articulo, $ordenInicial) {
            $chunks[] = new ChunkArticulo(
                orden: $ordenInicial + count($chunks),
                contenido: $texto,
                titulo: $ctx['titulo'],
                capitulo: $ctx['capitulo'],
                seccion: $ctx['seccion'],
                articulo: $articulo['numero'],
                fraccion: $fraccion,
                inciso: $inciso,
                contexto: $contexto,
            );
        };

        [$proemio, $fracciones] = $this->separar(explode("\n", $contenido), self::RE_FRACCION);

        // Sin fracciones que lo estructuren (o con una sola), el artículo va entero.
        if (count($fracciones) < 2) {
            if (mb_strlen($contenido) <= $this->maxCaracteres) {
                $agregar($contenido);
            } else {
                foreach ($this->trocearPorTamano($contenido) as $parte) {
                    $agregar($parte->contenido, fraccion: 'parte '.($parte->orden + 1));
                }
            }

            return $chunks;
        }

        // El proemio puede contener la regla misma ("La vuelta a la derecha siempre
        // será continua, excepto…") y no solo "se entiende por:"; si tiene
        // sustancia, también es un apartado buscable por sí mismo.
        $sustancia = preg_replace('/^(?:ART[ÍI]CULO|Art[íi]culo|Art\.)\s*\d+\S*\s*[\.\-–—:]+\s*/u', '', $proemio);
        if (mb_strlen(trim($sustancia)) >= $this->minProemioPropio) {
            $agregar($proemio);
        }

        foreach ($fracciones as $fraccion) {
            if (preg_match(self::RE_DEROGADO, $fraccion['texto'])) {
                continue;
            }

            [$cabecera, $incisos] = $this->separar(explode("\n", $fraccion['texto']), self::RE_INCISO);
            $incisos = array_filter($incisos, fn ($i) => ! preg_match(self::RE_DEROGADO, $i['texto']));

            if ($incisos && mb_strlen($fraccion['texto']) >= $this->minParaIncisos) {
                // Contexto del inciso: proemio del artículo + encabezado de su fracción.
                $contexto = trim($proemio."\n".$cabecera);

                foreach ($incisos as $inciso) {
                    $agregar($inciso['texto'], $contexto, $fraccion['numero'], $inciso['numero']);
                }

                continue;
            }

            $partes = mb_strlen($fraccion['texto']) <= $this->maxCaracteres
                ? [$fraccion['texto']]
                : array_map(fn (ChunkArticulo $p) => $p->contenido, $this->trocearPorTamano($fraccion['texto']));

            foreach ($partes as $texto) {
                $agregar($texto, $proemio ?: null, $fraccion['numero']);
            }
        }

        return $chunks;
    }

    /**
     * Separa líneas en el texto previo al primer marcador y los bloques que
     * empiezan con él (fracciones "I." o incisos "a)").
     *
     * @return array{0: string, 1: list<array{numero: string, texto: string}>}
     */
    private function separar(array $lineas, string $marcador): array
    {
        $previo = [];
        $bloques = [];

        foreach ($lineas as $linea) {
            if (preg_match($marcador, $linea, $m)) {
                $bloques[] = ['numero' => $m[1], 'texto' => $linea];
            } elseif ($bloques) {
                $bloques[array_key_last($bloques)]['texto'] .= "\n".$linea;
            } else {
                $previo[] = $linea;
            }
        }

        return [trim(implode("\n", $previo)), $bloques];
    }

    /** @return list<ChunkArticulo> */
    private function trocearPorTamano(string $texto): array
    {
        $partes = [];
        $actual = '';

        foreach (preg_split("/\n+/", trim($texto)) as $parrafo) {
            if ($actual !== '' && mb_strlen($actual) + mb_strlen($parrafo) > $this->maxCaracteres) {
                $partes[] = $actual;
                $actual = '';
            }

            // Un párrafo único más largo que el máximo se corta en duro.
            foreach (mb_str_split($parrafo, $this->maxCaracteres) as $pedazo) {
                if ($actual !== '' && mb_strlen($actual) + mb_strlen($pedazo) > $this->maxCaracteres) {
                    $partes[] = $actual;
                    $actual = '';
                }

                $actual = $actual === '' ? $pedazo : "{$actual}\n{$pedazo}";
            }
        }

        if ($actual !== '') {
            $partes[] = $actual;
        }

        return array_map(
            fn (string $parte, int $i) => new ChunkArticulo(orden: $i, contenido: $parte),
            $partes,
            array_keys($partes),
        );
    }

    private function limpiarEncabezado(string $linea): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $linea), " .-–—"), 0, 255);
    }

    private function normalizarNumero(string $numero): string
    {
        $numero = preg_replace(['/\s*[oº°]$/u', '/\s+/u'], ['', ' '], trim($numero));

        return mb_convert_case($numero, MB_CASE_TITLE);
    }
}
