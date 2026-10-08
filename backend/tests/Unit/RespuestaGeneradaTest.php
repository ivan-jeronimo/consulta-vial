<?php

namespace Tests\Unit;

use App\Services\Consulta\RespuestaGenerada;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RespuestaGeneradaTest extends TestCase
{
    public static function variantes(): array
    {
        return [
            'simple' => ["Respuesta.\n\nFUENTES: 1, 4", [0, 3]],
            'con negritas' => ["Respuesta.\n**FUENTES:** 2", [1]],
            'con corchetes' => ["Respuesta.\nFuentes: [1], [3]", [0, 2]],
            'repetidas y fuera de rango' => ["Respuesta.\nFUENTES: 2, 2, 9", [1]],
            'ninguna' => ["No hay información.\nFUENTES: ninguna", []],
        ];
    }

    #[DataProvider('variantes')]
    public function test_separa_las_fuentes_del_texto(string $texto, array $esperadas): void
    {
        $r = RespuestaGenerada::desdeTexto($texto, 8, 'modelo');

        $this->assertSame($esperadas, $r->fuentesUsadas);
        $this->assertStringNotContainsStringIgnoringCase('fuentes', $r->texto);
        $this->assertStringStartsWith(explode("\n", $texto)[0], $r->texto);
    }

    public function test_lee_si_la_respuesta_esta_cubierta_en_cualquier_orden(): void
    {
        $no = RespuestaGenerada::desdeTexto("No lo regula.\n\nCUBIERTO: no\nFUENTES: ninguna", 8, 'm');
        $si = RespuestaGenerada::desdeTexto("Sí.\nFUENTES: 2\n**CUBIERTO:** Sí", 8, 'm');

        $this->assertFalse($no->cubierto);
        $this->assertSame([], $no->fuentesUsadas);
        $this->assertSame('No lo regula.', $no->texto);

        $this->assertTrue($si->cubierto);
        $this->assertSame([1], $si->fuentesUsadas);
        $this->assertSame('Sí.', $si->texto);

        $this->assertNull(RespuestaGenerada::desdeTexto("Texto.\nFUENTES: 1", 8, 'm')->cubierto);
    }

    public function test_sin_linea_de_fuentes_no_filtra(): void
    {
        $r = RespuestaGenerada::desdeTexto("Respuesta sin la línea final.", 8, 'modelo');

        $this->assertNull($r->fuentesUsadas);
        $this->assertSame('Respuesta sin la línea final.', $r->texto);
    }

    public function test_no_confunde_la_palabra_fuentes_dentro_del_texto(): void
    {
        $r = RespuestaGenerada::desdeTexto("Según las fuentes: el Art. 54 lo permite.\nMás texto.", 8, 'modelo');

        $this->assertNull($r->fuentesUsadas);
    }
}
