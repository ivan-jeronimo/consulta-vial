<?php

namespace App\Services\Consulta;

final readonly class RespuestaGenerada
{
    /**
     * @param  list<int>|null  $fuentesUsadas  Posiciones (base 0) de los artículos que el
     *                                         modelo declaró usar; null si no lo declaró.
     * @param  bool|null  $cubierto  Si los artículos responden la pregunta; null si no lo declaró.
     */
    public function __construct(
        public string $texto,
        public ?array $fuentesUsadas,
        public string $modelo,
        public ?bool $cubierto = null,
    ) {}

    /**
     * Separa las líneas de control que se piden al modelo al final de su
     * respuesta, en cualquier orden:
     *   CUBIERTO: sí | no
     *   FUENTES: 1, 4 | ninguna
     * Los números fuera de rango se ignoran.
     */
    public static function desdeTexto(string $texto, int $totalArticulos, string $modelo): self
    {
        $fuentes = null;
        $cubierto = null;
        $lineas = explode("\n", rtrim($texto));

        // Recorre desde el final mientras haya líneas de control (o vacías).
        while ($lineas) {
            $linea = trim(end($lineas), " \t*_");

            if ($linea === '') {
                array_pop($lineas);

                continue;
            }

            if (preg_match('/^FUENTES[*_]*\s*:[*_]*\s*(.*)$/iu', $linea, $m)) {
                preg_match_all('/\d+/', $m[1], $numeros);
                $fuentes = collect($numeros[0])
                    ->map(fn (string $n) => (int) $n - 1)
                    ->filter(fn (int $i) => $i >= 0 && $i < $totalArticulos)
                    ->unique()
                    ->values()
                    ->all();
            } elseif (preg_match('/^CUBIERTO[*_]*\s*:[*_]*\s*(s[ií]|no)\b/iu', $linea, $m)) {
                $cubierto = mb_strtolower($m[1]) !== 'no';
            } else {
                break;
            }

            array_pop($lineas);
        }

        return new self(trim(implode("\n", $lineas)), $fuentes, $modelo, $cubierto);
    }
}
