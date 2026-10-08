<?php

namespace Tests\Unit;

use App\Services\Ingesta\ChunkArticulo;
use App\Services\Ingesta\NormalizadorTexto;
use App\Services\Ingesta\SegmentadorSecciones;
use PHPUnit\Framework\TestCase;

class SegmentadorSeccionesTest extends TestCase
{
    private const MANUAL = <<<'TXT'
        MANUAL DEL CONDUCTOR DEL ESTADO DE OAXACA 2023

        TEMA 3 SEÑALES DE TRÁNSITO
        Las señales de tránsito son dispositivos que regulan la circulación y se clasifican en
        preventivas, restrictivas e informativas.

        3.1 Señales restrictivas
        Indican prohibiciones o limitaciones. Son de forma cuadrada con fondo blanco y
        símbolo negro dentro de un anillo rojo.

        3.2 Semáforos
        La flecha roja indica que el movimiento señalado por ella está prohibido mientras
        permanezca encendida, aunque otros movimientos puedan estar permitidos.

        PREGUNTAS DE PRÁCTICA
        1. ¿Qué indica una flecha roja en el semáforo?
        a) Que puede avanzar con precaución.
        b) Que el movimiento indicado está prohibido.
        TXT;

    /** @return list<ChunkArticulo> */
    private function segmentar(string $texto = self::MANUAL): array
    {
        return (new SegmentadorSecciones)->segmentar((new NormalizadorTexto)->normalizar($texto));
    }

    public function test_divide_por_temas_y_subtemas(): void
    {
        $ruta = array_map(fn (ChunkArticulo $c) => [$c->capitulo, $c->seccion], $this->segmentar());

        $this->assertSame([
            ['TEMA 3 SEÑALES DE TRÁNSITO', null],
            ['TEMA 3 SEÑALES DE TRÁNSITO', '3.1 Señales restrictivas'],
            ['TEMA 3 SEÑALES DE TRÁNSITO', '3.2 Semáforos'],
            ['PREGUNTAS DE PRÁCTICA', null],
        ], $ruta);
    }

    public function test_el_texto_del_tema_no_se_funde_con_su_titulo(): void
    {
        $semaforos = $this->segmentar()[2];

        $this->assertStringStartsWith('La flecha roja indica que el movimiento señalado', $semaforos->contenido);
        $this->assertNull($semaforos->articulo);
    }

    public function test_las_preguntas_de_examen_no_se_toman_como_encabezados(): void
    {
        $preguntas = $this->segmentar()[3];

        $this->assertStringContainsString('1. ¿Qué indica una flecha roja en el semáforo?', $preguntas->contenido);
        $this->assertStringContainsString('b) Que el movimiento indicado está prohibido.', $preguntas->contenido);
    }

    public function test_guia_en_mayusculas_con_vinetas_usa_solo_las_secciones_numeradas(): void
    {
        $chunks = $this->segmentar(<<<'TXT'
            ÍNDICE
            CONOCIMIENTOS GENERALES …………………………………………………………………………..
            3
            OBTENCIÓN LICENCIA TIPO “A” …………………………………………………………………………
            12

            I.-CONOCIMIENTOS GENERALES
            ● LA POLICÍA VIAL ES LA AUTORIDAD COMPETENTE QUE DEBE SABER DE LOS HECHOS DE TRÁNSITO
            ● CUANDO CIRCULE EN UNA VIALIDAD CON TRES CARRILES EN EL MISMO SENTIDO EL CARRIL DE EXTREMA IZQUIERDA ES EL
            INDICADO PARA REBASAR
            III.- OBTENCIÓN DE LICENCIA TIPO A
            ● NO ES OBLIGATORIO LLEVAR RUEDA DE REPUESTO EN UNA MOTOCICLETA
            ● PARA RECARGAR COMBUSTIBLE EN NUESTRA MOTOCICLETA, DEBEMOS APAGAR EL MOTOR
            TXT);

        $this->assertSame(
            ['I.-CONOCIMIENTOS GENERALES', 'I.-CONOCIMIENTOS GENERALES', 'III.- OBTENCIÓN DE LICENCIA TIPO A', 'III.- OBTENCIÓN DE LICENCIA TIPO A'],
            array_map(fn (ChunkArticulo $c) => $c->capitulo, $chunks),
            'Las viñetas en mayúsculas no son títulos; el índice se descarta; cada viñeta es un apartado.',
        );
        $this->assertSame(
            '● CUANDO CIRCULE EN UNA VIALIDAD CON TRES CARRILES EN EL MISMO SENTIDO EL CARRIL DE EXTREMA IZQUIERDA ES EL INDICADO PARA REBASAR',
            $chunks[1]->contenido,
            'La viñeta partida en dos renglones se une, y no se mezcla con las demás.',
        );
        $this->assertSame('● NO ES OBLIGATORIO LLEVAR RUEDA DE REPUESTO EN UNA MOTOCICLETA', $chunks[2]->contenido);
    }

    public function test_pregunta_de_examen_queda_junto_con_sus_opciones(): void
    {
        $chunks = $this->segmentar("PREGUNTAS\n1. ¿Qué indica la luz ámbar?\na) Acelerar.\nb) Detenerse con precaución.\n2. ¿Quién tiene preferencia?\na) El peatón.");

        $this->assertSame("1. ¿Qué indica la luz ámbar?\na) Acelerar.\nb) Detenerse con precaución.", $chunks[0]->contenido);
        $this->assertSame("2. ¿Quién tiene preferencia?\na) El peatón.", $chunks[1]->contenido);
    }

    public function test_secciones_largas_se_parten_en_fragmentos_de_tamano_acotado(): void
    {
        $parrafos = implode("\n\n", array_map(
            fn ($i) => "Párrafo {$i}. ".str_repeat('El conductor debe respetar los límites de velocidad. ', 8),
            range(1, 12),
        ));
        $chunks = $this->segmentar("TEMA 5 VELOCIDAD\n\n{$parrafos}");

        $this->assertGreaterThan(3, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(2000, mb_strlen($chunk->contenido));
            $this->assertSame('TEMA 5 VELOCIDAD', $chunk->capitulo);
        }
        $this->assertStringStartsWith('Párrafo 1.', $chunks[0]->contenido, 'Se corta entre párrafos, no a la mitad.');
    }
}
