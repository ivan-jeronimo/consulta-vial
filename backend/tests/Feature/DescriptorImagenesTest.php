<?php

namespace Tests\Feature;

use App\Services\Ingesta\DescriptorImagenes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DescriptorImagenesTest extends TestCase
{
    public function test_detecta_las_paginas_con_mas_imagenes_que_la_decoracion_habitual(): void
    {
        // Como la guía de examen: logo + franja en todas; señales en 10 y 11.
        $conteo = array_fill(1, 21, 2);
        $conteo[1] = $conteo[10] = $conteo[11] = 3;

        $this->assertSame([1, 10, 11], DescriptorImagenes::paginasIlustradas($conteo));

        // Documento sin decoración: cualquier página con imágenes cuenta.
        $this->assertSame([2], DescriptorImagenes::paginasIlustradas([1 => 0, 2 => 4, 3 => 0]));
        $this->assertSame([], DescriptorImagenes::paginasIlustradas([1 => 2, 2 => 2]));
    }

    public function test_envia_la_pagina_como_imagen_y_devuelve_las_senales_descritas(): void
    {
        config(['rag.llm.provider' => 'gemini', 'rag.llm.model' => 'modelo-vision, respaldo', 'rag.embeddings.gemini.key' => 'g-prueba']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['text' => '● SEÑAL «CEDA EL PASO»: triángulo invertido blanco con borde rojo.'],
        ]]]]])]);

        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, 'imagen-de-prueba');

        $texto = app(DescriptorImagenes::class)->describirImagen($png, 10);

        $this->assertSame('● SEÑAL «CEDA EL PASO»: triángulo invertido blanco con borde rojo.', $texto);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/models/modelo-vision:generateContent')
            && $r['contents'][0]['parts'][0]['inline_data']['data'] === base64_encode('imagen-de-prueba')
            && str_contains($r['contents'][0]['parts'][1]['text'], 'página 10'));

        unlink($png);
    }

    public function test_una_pagina_solo_decorativa_no_aporta_texto(): void
    {
        config(['rag.llm.provider' => 'gemini', 'rag.llm.model' => 'modelo-vision', 'rag.embeddings.gemini.key' => 'g-prueba']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'NINGUNA']]]]]])]);

        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, 'x');

        $this->assertNull(app(DescriptorImagenes::class)->describirImagen($png, 1));

        unlink($png);
    }

    public function test_sin_gemini_configurado_no_se_describe_nada(): void
    {
        config(['rag.llm.provider' => 'anthropic']);

        $this->assertFalse(app(DescriptorImagenes::class)->disponible());
        $this->assertSame([], app(DescriptorImagenes::class)->describir('/no/existe.pdf', 3));
    }
}
