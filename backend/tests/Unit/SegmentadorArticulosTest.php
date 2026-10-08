<?php

namespace Tests\Unit;

use App\Services\Ingesta\ChunkArticulo;
use App\Services\Ingesta\SegmentadorArticulos;
use PHPUnit\Framework\TestCase;

class SegmentadorArticulosTest extends TestCase
{
    private const LARGO = <<<'TXT'
        CAPÍTULO II
        ARTÍCULO 58. Son obligaciones de las personas conductoras de vehículos automotores para que puedan circular libremente en el Municipio.
        I. Cumplir las indicaciones de los semáforos para vehículos, de la siguiente manera:
        a) Ante una indicación de luz verde del semáforo, los vehículos podrán avanzar en la misma dirección, siempre que no existan semáforos especiales para personas peatonas en el cruce.
        b) Frente a una indicación de flecha verde exhibida sola o combinada con otra señal, los vehículos podrán entrar en la intersección para efectuar el movimiento indicado por la flecha.
        c) Ante la indicación de luz amarilla del semáforo, las personas conductoras no deberán entrar a la intersección, excepto que el vehículo se encuentre ya en ella o detenerlo signifique peligro.
        d) Frente a una indicación de luz roja del semáforo, las personas conductoras deberán detener la marcha antes de la raya de alto total, respetando los señalamientos del paso peatonal.
        II. Conducir con precaución, en pleno uso de sus facultades físicas y mentales;
        III. Derogada.
        IV. Usar el cinturón de seguridad, así como sus pasajeros;
        ARTÍCULO 59. Para los efectos de este Reglamento se entiende por:
        I. Vehículo: todo medio de transporte terrestre;
        II. Peatón: la persona que transita a pie.
        TXT;

    /** @return array<string, ChunkArticulo> indexado por "articulo/fraccion/inciso" */
    private function segmentar(): array
    {
        $chunks = (new SegmentadorArticulos)->segmentar(self::LARGO);

        return collect($chunks)->keyBy(fn (ChunkArticulo $c) => "{$c->articulo}/{$c->fraccion}/{$c->inciso}")->all();
    }

    public function test_divide_articulos_largos_en_fracciones_e_incisos_con_su_encabezado(): void
    {
        $chunks = $this->segmentar();

        $this->assertSame(
            ['58//', '58/I/a', '58/I/b', '58/I/c', '58/I/d', '58/II/', '58/IV/', '59/I/', '59/II/'],
            array_keys($chunks),
        );

        $flecha = $chunks['58/I/b'];
        $this->assertStringStartsWith('b) Frente a una indicación de flecha verde', $flecha->contenido);
        $this->assertStringNotContainsString('luz roja', $flecha->contenido, 'Solo el inciso, no el artículo completo.');
        $this->assertStringContainsString('Son obligaciones de las personas conductoras', $flecha->contexto);
        $this->assertStringContainsString('I. Cumplir las indicaciones de los semáforos', $flecha->contexto);
        $this->assertSame('CAPÍTULO II', $flecha->capitulo);

        $this->assertStringContainsString('Son obligaciones', $chunks['58/II/']->contexto);
        $this->assertStringNotContainsString('semáforos', $chunks['58/II/']->contexto, 'La fracción II no hereda la cabecera de la I.');
    }

    public function test_omite_fracciones_derogadas(): void
    {
        $this->assertArrayNotHasKey('58/III/', $this->segmentar());
    }

    public function test_articulo_corto_con_fracciones_tambien_se_divide(): void
    {
        $chunks = $this->segmentar();

        $this->assertSame('II. Peatón: la persona que transita a pie.', $chunks['59/II/']->contenido);
        $this->assertStringContainsString('se entiende por', $chunks['59/II/']->contexto);
    }

    public function test_el_proemio_con_sustancia_es_un_apartado_propio(): void
    {
        $chunks = $this->segmentar();

        $this->assertStringStartsWith('ARTÍCULO 58. Son obligaciones', $chunks['58//']->contenido);
        $this->assertNull($chunks['58//']->contexto);
        $this->assertArrayNotHasKey('59//', $chunks, '"Se entiende por:" no aporta nada por sí solo.');
    }

    public function test_reconoce_capitulos_sin_espacio_y_no_los_pega_al_articulo_anterior(): void
    {
        $chunks = (new SegmentadorArticulos)->segmentar(<<<'TXT'
            CAPÍTULO TERCERO ACCIONES PARA LA SEGURIDAD VIAL
            ARTÍCULO 52. Los motociclistas y sus pasajeros deberán usar casco de seguridad.
            CAPÍTULOCUARTO OBLIGACIONES DE LOS CONDUCTORES DEL SERVICIO PÚBLICO
            ARTÍCULO 54. Los conductores del servicio público deberán portar su gafete.
            TXT);

        $this->assertStringNotContainsString('CUARTO', $chunks[0]->contenido);
        $this->assertSame('CAPÍTULOCUARTO OBLIGACIONES DE LOS CONDUCTORES DEL SERVICIO PÚBLICO', $chunks[1]->capitulo);
    }

    public function test_une_numeros_de_fraccion_sueltos_de_pdftotext(): void
    {
        $texto = (new \App\Services\Ingesta\NormalizadorTexto)->normalizar(
            "ARTÍCULO 53. Los conductores de motocicletas, tienen prohibido:\nI.\n\nCircular sin la licencia de conducir, tarjeta de circulación y placas\nvigentes;\n\nII.\n\nTransportar personas adelante del conductor;"
        );
        $chunks = collect((new SegmentadorArticulos)->segmentar($texto))->keyBy('fraccion');

        $this->assertSame('I. Circular sin la licencia de conducir, tarjeta de circulación y placas vigentes;', $chunks['I']->contenido);
        $this->assertSame('II. Transportar personas adelante del conductor;', $chunks['II']->contenido);
    }

    public function test_el_texto_para_embedding_incluye_ruta_y_encabezado(): void
    {
        $texto = $this->segmentar()['58/I/b']->textoParaEmbedding('Reglamento de Movilidad');

        $this->assertStringStartsWith('Reglamento de Movilidad > CAPÍTULO II > Artículo 58 > Fracción I > Inciso b)', $texto);
        $this->assertStringContainsString('Son obligaciones', $texto);
        $this->assertStringEndsWith('movimiento indicado por la flecha.', $texto);
    }
}
