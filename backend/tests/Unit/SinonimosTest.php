<?php

namespace Tests\Unit;

use App\Services\Consulta\Sinonimos;
use PHPUnit\Framework\TestCase;

class SinonimosTest extends TestCase
{
    public function test_agrega_las_palabras_con_que_lo_dice_el_reglamento(): void
    {
        $consulta = Sinonimos::expandir('¿Qué debe hacer alguien que está aprendiendo a conducir?');

        $this->assertStringStartsWith('¿Qué debe hacer alguien que está aprendiendo a conducir?', $consulta);
        $this->assertStringContainsString('enseñanza', $consulta);
        $this->assertStringContainsString('escuela de manejo', $consulta);
        $this->assertStringContainsString('teléfono', Sinonimos::expandir('¿Puedo usar el celular en la bici?'));
        $this->assertStringContainsString('menores de edad', Sinonimos::expandir('¿Puede ir un niño adelante?'));
    }

    public function test_sin_coincidencias_deja_la_pregunta_igual(): void
    {
        $this->assertSame('¿Cuánto cuesta la verificación?', Sinonimos::expandir('¿Cuánto cuesta la verificación?'));
    }
}
