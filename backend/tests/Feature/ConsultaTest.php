<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\DocumentoReglamento;
use App\Models\Estado;
use App\Models\Municipio;
use App\Services\Ingesta\RegistroReglamentos;
use Database\Seeders\CatalogoTerritorialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConsultaTest extends TestCase
{
    use RefreshDatabase;

    private const MUNICIPAL = <<<'TXT'
        CAPÍTULO II
        DE LAS REGLAS DE TRÁNSITO
        ARTÍCULO 53. Para dar vuelta en un crucero, la persona conductora deberá hacer la señal correspondiente.
        ARTÍCULO 54. La vuelta a la derecha siempre será continua, excepto en los casos en donde existan señales restrictivas.
        ARTÍCULO 58. Frente a una indicación de flecha verde, los vehículos podrán efectuar el movimiento indicado por la flecha.
        ARTÍCULO 147. La Tesorería Municipal adoptará las medidas que permitan el cobro de multas.
        TXT;

    private const ESTATAL = <<<'TXT'
        ARTÍCULO 10. Los conductores que den vuelta a la derecha deberán ceder el paso a los peatones.
        ARTÍCULO175. El Consejo Estatal celebrará sesiones ordinarias.
        TXT;

    private Municipio $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('rag.ingesta.disk'));
        $this->seed(CatalogoTerritorialSeeder::class);

        $oaxaca = Estado::where('clave_inegi', '20')->firstOrFail();
        $this->municipio = Municipio::where('slug', 'oaxaca-de-juarez')->firstOrFail();
        $registro = app(RegistroReglamentos::class);

        $registro->registrar(
            ['titulo' => 'Reglamento Municipal', 'nivel' => 'municipal', 'estado_id' => $oaxaca->id, 'municipio_id' => $this->municipio->id, 'anio' => 2026],
            $registro->guardarTexto(self::MUNICIPAL),
        );
        $registro->registrar(
            ['titulo' => 'Reglamento Estatal', 'nivel' => 'estatal', 'estado_id' => $oaxaca->id, 'anio' => 2024],
            $registro->guardarTexto(self::ESTATAL),
        );
    }

    public function test_encuentra_el_articulo_por_nombre_de_municipio_y_avisa_si_no_hay_llm(): void
    {
        config(['rag.llm.anthropic.key' => null]);

        $respuesta = $this->postJson('/api/consulta', [
            'municipio' => 'Oaxaca de Juárez',
            'estado' => 'Oaxaca',
            'pregunta' => '¿Se permite la vuelta a la derecha continua con flecha roja?',
        ])->assertOk();

        $respuesta->assertJsonPath('ubicacion', 'Oaxaca de Juárez, Oaxaca')
            ->assertJsonPath('respuesta', null)
            ->assertJsonPath('fuentes.0.articulo', '54');

        $articulos = collect($respuesta->json('fuentes'))->pluck('articulo');
        $this->assertContains('10', $articulos, 'Debe incluir la norma estatal aplicable.');
        $this->assertNotContains('147', $articulos, 'Coincidir solo en "permite" no basta.');
        $this->assertStringContainsString('LLM no configurado', $respuesta->json('aviso'));
    }

    public function test_el_segmentador_reconoce_articulos_sin_espacio(): void
    {
        $this->assertSame(['10', '175'], DocumentoReglamento::where('titulo', 'Reglamento Estatal')->sole()
            ->articulos()->orderBy('orden')->pluck('articulo')->all());
    }

    public function test_no_incluye_reglamentos_de_otro_municipio_ni_derogados(): void
    {
        $otro = Municipio::where('slug', 'santa-cruz-xoxocotlan')->firstOrFail();

        $fuentes = $this->postJson('/api/consulta', [
            'municipio_id' => $otro->id,
            'pregunta' => 'vuelta a la derecha continua',
        ])->assertOk()->json('fuentes');

        $this->assertSame(['Reglamento Estatal'], collect($fuentes)->pluck('reglamento')->unique()->values()->all());

        DocumentoReglamento::query()->update(['estatus_vigencia' => 'derogado']);

        $this->postJson('/api/consulta', ['municipio_id' => $this->municipio->id, 'pregunta' => 'vuelta a la derecha continua'])
            ->assertOk()
            ->assertJsonPath('fuentes', [])
            ->assertJsonPath('aviso', 'No se encontraron artículos aplicables para esta ubicación.');
    }

    public function test_redacta_la_respuesta_con_el_llm_usando_los_articulos_como_contexto(): void
    {
        config(['rag.llm.provider' => 'anthropic', 'rag.llm.anthropic.key' => 'sk-prueba']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'No. La flecha roja es una señal restrictiva (Art. 54).']],
        ])]);

        $this->postJson('/api/consulta', [
            'municipio' => 'Oaxaca de Juárez',
            'pregunta' => '¿Se permite la vuelta a la derecha continua con flecha roja?',
        ])
            ->assertOk()
            ->assertJsonPath('respuesta', 'No. La flecha roja es una señal restrictiva (Art. 54).')
            ->assertJsonPath('modelo', 'claude-sonnet-5-5');

        Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'sk-prueba')
            && $r['model'] === 'claude-sonnet-5-5'
            && str_contains($r['messages'][0]['content'], 'Ubicación del usuario: Oaxaca de Juárez, Oaxaca')
            && str_contains($r['messages'][0]['content'], 'La vuelta a la derecha siempre será continua'));
    }

    public function test_redacta_la_respuesta_con_gemini_omitiendo_los_pensamientos(): void
    {
        config(['rag.llm.provider' => 'gemini', 'rag.llm.model' => 'gemini-modelo-prueba', 'rag.embeddings.gemini.key' => 'g-prueba']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'Analizando artículos…', 'thought' => true],
                ['text' => 'No. Es una señal restrictiva (Art. 54).'],
            ]], 'finishReason' => 'STOP']],
        ])]);

        $this->postJson('/api/consulta', ['municipio' => 'Oaxaca de Juárez', 'pregunta' => 'vuelta a la derecha con flecha roja'])
            ->assertOk()
            ->assertJsonPath('respuesta', 'No. Es una señal restrictiva (Art. 54).')
            ->assertJsonPath('modelo', 'gemini-modelo-prueba');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/models/gemini-modelo-prueba:generateContent')
            && $r->hasHeader('x-goog-api-key', 'g-prueba')
            && str_contains($r['contents'][0]['parts'][0]['text'], 'La vuelta a la derecha siempre será continua'));
    }

    public function test_devuelve_solo_los_apartados_que_la_respuesta_uso(): void
    {
        config(['rag.llm.provider' => 'gemini', 'rag.llm.model' => 'modelo-a', 'rag.embeddings.gemini.key' => 'g-prueba']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['text' => "No se permite (Art. 54).\n\n*Información orientativa.*\n\nFUENTES: 1"],
        ]]]]])]);

        $respuesta = $this->postJson('/api/consulta', [
            'municipio' => 'Oaxaca de Juárez',
            'pregunta' => '¿Se permite la vuelta a la derecha continua con flecha roja?',
        ])->assertOk();

        $this->assertSame("No se permite (Art. 54).\n\n*Información orientativa.*", $respuesta->json('respuesta'));
        $this->assertSame(['54'], collect($respuesta->json('fuentes'))->pluck('articulo')->all());
    }

    public function test_si_el_estatal_no_cubre_la_pregunta_sugiere_municipios_con_reglamento(): void
    {
        config(['rag.llm.provider' => 'gemini', 'rag.llm.model' => 'modelo-a', 'rag.embeddings.gemini.key' => 'g-prueba']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['text' => "El reglamento estatal no regula ese caso.\n\nCUBIERTO: no\nFUENTES: ninguna"],
        ]]]]])]);

        $pregunta = ['pregunta' => '¿puedo dar vuelta a la derecha con flecha roja en el semáforo?'];

        $this->postJson('/api/consulta', [...$pregunta, 'estado' => 'Oaxaca'])
            ->assertOk()
            ->assertJsonPath('respuesta', 'El reglamento estatal no regula ese caso.')
            ->assertJsonPath('sugerir_municipios', [['id' => $this->municipio->id, 'nombre' => 'Oaxaca de Juárez']]);

        // Con municipio elegido no hay nada más que sugerir.
        $this->postJson('/api/consulta', [...$pregunta, 'municipio_id' => $this->municipio->id])
            ->assertOk()
            ->assertJsonPath('sugerir_municipios', []);
    }

    public function test_si_el_modelo_preferido_esta_saturado_usa_el_de_respaldo(): void
    {
        config(['rag.llm.provider' => 'gemini', 'rag.llm.model' => 'modelo-a, modelo-b', 'rag.embeddings.gemini.key' => 'g-prueba']);
        Http::fake([
            '*/models/modelo-a:generateContent' => Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503),
            '*/models/modelo-b:generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Respuesta de respaldo']]]]]]),
        ]);

        $this->postJson('/api/consulta', ['municipio' => 'Oaxaca de Juárez', 'pregunta' => 'vuelta a la derecha continua'])
            ->assertOk()
            ->assertJsonPath('respuesta', 'Respuesta de respaldo')
            ->assertJsonPath('modelo', 'modelo-b');
    }

    public function test_si_el_llm_falla_devuelve_los_articulos_con_aviso(): void
    {
        config(['rag.llm.provider' => 'gemini', 'rag.llm.model' => 'modelo-a', 'rag.embeddings.gemini.key' => 'g-prueba']);
        Http::fake(['*' => Http::response(['error' => ['code' => 503]], 503)]);

        $this->postJson('/api/consulta', ['municipio' => 'Oaxaca de Juárez', 'pregunta' => 'vuelta a la derecha continua'])
            ->assertOk()
            ->assertJsonPath('respuesta', null)
            ->assertJsonPath('fuentes.0.articulo', '54')
            ->assertJsonPath('aviso', 'No pude redactar la respuesta en este momento; estos son los artículos del reglamento relacionados con tu pregunta.');
    }

    public function test_territorios_lista_solo_lugares_con_normativa_consultable(): void
    {
        $this->getJson('/api/territorios')
            ->assertOk()
            ->assertExactJson([
                'hay_normativa_federal' => false,
                'estados' => [[
                    'id' => $this->municipio->estado_id,
                    'nombre' => 'Oaxaca',
                    'tiene_reglamento_estatal' => true,
                    'municipios' => [['id' => $this->municipio->id, 'nombre' => 'Oaxaca de Juárez']],
                ]],
            ]);

        DocumentoReglamento::where('nivel', 'municipal')->update(['estatus_vigencia' => 'derogado']);

        $this->getJson('/api/territorios')->assertJsonPath('estados.0.municipios', []);
    }

    public function test_valida_municipio_desconocido(): void
    {
        $this->postJson('/api/consulta', ['municipio' => 'Springfield', 'pregunta' => 'vuelta a la derecha'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('municipio');
    }

    public function test_limite_diario_por_persona_y_origen_del_chat(): void
    {
        config(['rag.llm.anthropic.key' => null, 'rag.limites.por_persona_al_dia' => 2, 'rag.limites.total_al_dia' => 3]);
        $ana = '0b2f7a6e-5c1d-4e8b-9a3f-2d6c8e1f4a7b';
        $pregunta = fn (string $visitante) => [
            'municipio' => 'Oaxaca de Juárez', 'estado' => 'Oaxaca', 'pregunta' => '¿Puedo dar vuelta a la derecha?',
            'visitante' => $visitante, 'origen' => 'andantes',
        ];

        $this->postJson('/api/consulta', $pregunta($ana))->assertOk();
        $this->postJson('/api/consulta', $pregunta($ana))->assertOk();
        $this->postJson('/api/consulta', $pregunta($ana))
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Llegaste al límite de 2 preguntas por hoy. Vuelve mañana para seguir preguntando.');

        // Otra persona sí puede, hasta que se acaba el tope de todo el servicio.
        $this->postJson('/api/consulta', $pregunta('7c9e6679-7425-40de-944b-e07fc1f90ae7'))->assertOk();
        $this->postJson('/api/consulta', $pregunta('9b2f8c1d-1111-4e8b-9a3f-2d6c8e1f4a7b'))
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'El asistente ya respondió todas las preguntas que puede por hoy. Vuelve mañana.');

        $this->assertSame(['andantes'], Consulta::pluck('origen')->unique()->values()->all());
        $this->assertSame(3, Consulta::count());

        // Al día siguiente se reinicia.
        $this->travel(1)->days();
        $this->postJson('/api/consulta', $pregunta($ana))->assertOk();
    }

    public function test_con_municipio_tambien_consulta_el_estatal_aunque_el_municipal_tenga_mas_coincidencias(): void
    {
        config(['rag.llm.anthropic.key' => null, 'rag.busqueda.resultados' => 3, 'rag.busqueda.minimo_por_nivel' => 1]);
        $oaxaca = $this->municipio->estado;
        $registro = app(RegistroReglamentos::class);
        $registro->registrar(
            ['titulo' => 'Reglamento de Motos', 'nivel' => 'municipal', 'estado_id' => $oaxaca->id, 'municipio_id' => $this->municipio->id, 'anio' => 2026],
            $registro->guardarTexto(implode("\n", [
                'ARTÍCULO 200. Es obligatorio usar casco en motocicleta para la persona conductora.',
                'ARTÍCULO 201. Es obligatorio usar casco en motocicleta para la persona acompañante.',
                'ARTÍCULO 202. Es obligatorio usar casco certificado en motocicleta en todo momento.',
                'ARTÍCULO 203. Es obligatorio usar casco abrochado al circular en motocicleta.',
            ])),
        );
        $registro->registrar(
            ['titulo' => 'Ley Estatal de Motos', 'nivel' => 'estatal', 'estado_id' => $oaxaca->id, 'anio' => 2024],
            $registro->guardarTexto('ARTÍCULO 300. Quien maneje una motocicleta llevará casco.'),
        );
        $pregunta = '¿Es obligatorio usar casco en motocicleta?';

        $respuesta = $this->postJson('/api/consulta', ['municipio_id' => $this->municipio->id, 'pregunta' => $pregunta])->assertOk();

        $articulos = collect($respuesta->json('fuentes'))->pluck('articulo');
        $this->assertCount(3, $articulos);
        $this->assertContains('300', $articulos, 'El estatal aplica también en el municipio.');
        $respuesta->assertJsonPath('ambitos', [
            ['nivel' => 'municipal', 'nombre' => 'Oaxaca de Juárez'],
            ['nivel' => 'estatal', 'nombre' => 'Oaxaca'],
        ]);

        // Solo el estado: solo el ámbito estatal.
        $this->postJson('/api/consulta', ['estado_id' => $oaxaca->id, 'pregunta' => $pregunta])
            ->assertJsonPath('ambitos', [['nivel' => 'estatal', 'nombre' => 'Oaxaca']]);
    }
}
