<?php

namespace Tests\Feature;

use App\Filament\Resources\PreguntasReto\Pages\EditPreguntaReto;
use App\Filament\Resources\PreguntasReto\Pages\ListPreguntasReto;
use App\Models\DocumentoReglamento;
use App\Models\Estado;
use App\Models\PreguntaReto;
use App\Models\User;
use App\Services\Ingesta\RegistroReglamentos;
use App\Services\Reto\GeneradorPreguntas;
use Database\Seeders\CatalogoTerritorialSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PreguntasRetoTest extends TestCase
{
    use RefreshDatabase;

    private const GUIA = <<<'TXT'
        I.-CONOCIMIENTOS GENERALES
        ● CUANDO UN CONDUCTOR QUE CIRCULA DETRÁS DE USTED LO PRETENDE REBASAR, USTED DEBE BAJAR LA VELOCIDAD Y PERMITIR QUE LO REBASEN.
        ● EN UNA GLORIETA TIENEN PREFERENCIA DE PASO LOS VEHÍCULOS QUE YA CIRCULAN DENTRO DE ELLA.
        II.-SEÑALES DE TRÁNSITO
        ● LAS SEÑALES RESTRICTIVAS TIENEN FORMA CUADRADA CON UN ANILLO ROJO Y INDICAN LIMITACIONES O PROHIBICIONES.
        TXT;

    private DocumentoReglamento $guia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('rag.ingesta.disk'));
        $this->seed(CatalogoTerritorialSeeder::class);
        config([
            'rag.llm.provider' => 'gemini',
            'rag.llm.model' => 'gemini-prueba',
            'rag.embeddings.gemini.key' => 'clave',
        ]);

        $registro = app(RegistroReglamentos::class);
        $this->guia = $registro->registrar(
            ['titulo' => 'Guía de Examen de Manejo Oaxaca 2023', 'tipo' => 'manual', 'nivel' => 'estatal', 'estado_id' => Estado::where('clave_inegi', '20')->value('id'), 'anio' => 2023],
            $registro->guardarTexto(self::GUIA),
        )->refresh();
    }

    /** @param  list<array>  $preguntas */
    private function fingirLlm(array $preguntas): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['preguntas' => $preguntas])]]]]],
        ])]);
    }

    private function preguntaValida(array $cambios = []): array
    {
        return [
            'fragmento' => 1,
            'pregunta' => 'Si un conductor detrás de usted quiere rebasarlo, ¿qué debe hacer?',
            'opciones' => ['Bajar la velocidad y dejar que rebase', 'Acelerar para no ser rebasado', 'Tocar el claxon', 'Cambiarse al carril izquierdo'],
            'correcta' => 0,
            'explicacion' => 'La guía indica bajar la velocidad y permitir el rebase.',
            ...$cambios,
        ];
    }

    public function test_genera_borradores_validos_y_baraja_las_opciones_sin_perder_la_correcta(): void
    {
        $this->fingirLlm([
            $this->preguntaValida(),
            $this->preguntaValida(['pregunta' => 'Sin opciones suficientes', 'opciones' => ['Sí', 'No']]),
            $this->preguntaValida(['pregunta' => 'Correcta fuera de rango', 'correcta' => 7]),
            $this->preguntaValida(['pregunta' => 'Fragmento inexistente', 'fragmento' => 99]),
        ]);

        $creadas = app(GeneradorPreguntas::class)->generar($this->guia);

        $this->assertSame(1, $creadas);
        $pregunta = PreguntaReto::sole();
        $this->assertSame('borrador', $pregunta->estado);
        $this->assertSame('Bajar la velocidad y dejar que rebase', $pregunta->respuestaCorrecta());
        $this->assertCount(4, $pregunta->opciones);
        $this->assertSame('Conocimientos generales', $pregunta->categoria);
        $this->assertStringContainsString('Guía de Examen de Manejo Oaxaca 2023', $pregunta->cita);
        $this->assertStringContainsString('PRETENDE REBASAR', $pregunta->textoFuente()->implode(' '));

        Http::assertSent(function (Request $r) {
            $cuerpo = $r->data();

            return $cuerpo['generationConfig']['responseMimeType'] === 'application/json'
                && str_contains($cuerpo['contents'][0]['parts'][0]['text'], 'GLORIETA');
        });
    }

    public function test_no_vuelve_a_procesar_fragmentos_ya_revisados(): void
    {
        $this->fingirLlm([$this->preguntaValida()]);
        $generador = app(GeneradorPreguntas::class);

        $antes = $generador->pendientes($this->guia)->count();
        $generador->generar($this->guia);

        $this->assertGreaterThan(0, $antes);
        $this->assertSame(0, $generador->pendientes($this->guia)->count());
        $this->assertSame(0, $generador->generar($this->guia)); // nada nuevo que revisar
        Http::assertSentCount(1);
    }

    public function test_categorias_legibles(): void
    {
        $this->assertSame('Conocimientos generales', GeneradorPreguntas::categoria('I.-CONOCIMIENTOS GENERALES'));
        $this->assertSame('Obtención de licencia tipo B', GeneradorPreguntas::categoria('IV.- PARA OBTENCIÓN DE LICENCIA TIPO B'));
        $this->assertSame('Obtención de licencia tipo F', GeneradorPreguntas::categoria('VIII.- PARA OBTENCIÓN DE LICENCIA TIPO "F"'));
        $this->assertSame('General', GeneradorPreguntas::categoria(null));
    }

    public function test_revisar_en_el_panel(): void
    {
        $this->actingAs(User::factory()->create());
        $pregunta = PreguntaReto::create([
            'categoria' => 'Señales de tránsito', 'pregunta' => '¿Qué forma tienen las señales restrictivas?',
            'opciones' => ['Cuadrada con anillo rojo', 'Triangular', 'Circular azul', 'Rombo amarillo'], 'correcta' => 0,
        ]);

        Livewire::test(ListPreguntasReto::class)
            ->assertCanSeeTableRecords([$pregunta])
            ->callAction(TestAction::make('aprobar')->table($pregunta));
        $this->assertSame('aprobada', $pregunta->fresh()->estado);

        // Al editar, se puede cambiar cuál es la correcta y quitar la opción D.
        Livewire::test(EditPreguntaReto::class, ['record' => $pregunta->id])
            ->fillForm(['opciones' => ['Cuadrada con anillo rojo', 'Triangular', 'Circular azul', ''], 'correcta' => 0])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['Cuadrada con anillo rojo', 'Triangular', 'Circular azul'], $pregunta->fresh()->opciones);
    }
}
