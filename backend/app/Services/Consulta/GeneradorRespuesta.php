<?php

namespace App\Services\Consulta;

use App\Models\ReglamentoArticulo;
use App\Services\Llm\ClienteLlm;
use Illuminate\Support\Collection;

/**
 * Redacta la respuesta al ciudadano a partir de los artículos recuperados.
 * El modelo solo puede usar ese contexto y debe citarlo.
 */
class GeneradorRespuesta
{
    private const INSTRUCCIONES = <<<'TXT'
        Eres un asistente que orienta a conductores, ciclistas y peatones sobre los reglamentos de tránsito y movilidad en México.

        Reglas:
        - Responde ÚNICAMENTE con base en los artículos proporcionados. No uses conocimiento externo sobre otras leyes.
        - Empieza con una respuesta directa (sí / no / depende) en una o dos frases, y después explica.
        - Cita cada afirmación con el artículo y el reglamento, por ejemplo: (Art. 54, Reglamento de Movilidad de Oaxaca de Juárez).
        - Si el texto no menciona exactamente el caso preguntado, dilo con claridad y separa lo que el reglamento dice literalmente de lo que se interpreta a partir de él.
        - Si los artículos no permiten responder, dilo y no inventes.
        - Si hay normas de distinto nivel (federal, estatal, municipal), indica cuál aplica; en su municipio prevalece el reglamento municipal en lo que regula.
        - Cada fuente indica su tipo. Los reglamentos y leyes son obligatorios. Los manuales y guías (por ejemplo, el manual para el examen de manejo) son material de estudio: úsalos para explicar o dar ejemplos y cítalos por su sección (por ejemplo: Manual del Conductor de Oaxaca 2023, sección «Señales restrictivas»), pero si contradicen a un reglamento o ley, prevalece la norma y debes decirlo.
        - Lenguaje claro y breve, sin tecnicismos innecesarios. Escribe en español de México.
        - Termina con una línea indicando que es información orientativa y no asesoría legal.
        - Después de todo, agrega estas dos líneas finales, cada una aparte:
          CUBIERTO: sí  (si los artículos permiten responder la pregunta, aunque sea por interpretación) o CUBIERTO: no  (si no tratan el tema preguntado)
          FUENTES: seguido de los números entre corchetes de los artículos que usaste para responder, separados por comas (por ejemplo: FUENTES: 1, 4). Incluye solo los que respaldan tu respuesta. Si no usaste ninguno, escribe: FUENTES: ninguna
        TXT;

    public function __construct(private readonly ClienteLlm $llm) {}

    public function disponible(): bool
    {
        return $this->llm->disponible();
    }

    /** @return list<string> */
    public function modelos(): array
    {
        return $this->llm->modelos();
    }

    /** @param  Collection<int, ReglamentoArticulo>  $articulos */
    public function responder(string $pregunta, string $ubicacion, Collection $articulos): RespuestaGenerada
    {
        $mensaje = "Ubicación del usuario: {$ubicacion}\n\n"
            ."Artículos aplicables encontrados:\n\n"
            .$articulos->values()->map(fn (ReglamentoArticulo $a, int $i) => $this->formatearArticulo($a, $i + 1))->implode("\n\n")
            ."\n\nPregunta: {$pregunta}";

        ['texto' => $texto, 'modelo' => $modelo] = $this->llm->generar(self::INSTRUCCIONES, $mensaje);

        return RespuestaGenerada::desdeTexto($texto, $articulos->count(), $modelo);
    }

    private function formatearArticulo(ReglamentoArticulo $a, int $n): string
    {
        $doc = $a->documento;
        $ambito = collect([$doc->nivel->getLabel(), $doc->municipio?->nombre, $doc->estado?->nombre])->filter()->implode(', ');
        $tipo = $doc->tipo->esNormativo() ? $doc->tipo->getLabel() : "{$doc->tipo->getLabel()}, material de estudio, no norma";

        // Normas: ruta por artículo. Manuales: por tema y sección.
        $ruta = $a->articulo
            ? collect([
                "Artículo {$a->articulo}",
                $a->fraccion ? "fracción {$a->fraccion}" : null,
                $a->inciso ? "inciso {$a->inciso})" : null,
            ])->filter()->implode(', ')
            : (collect([$a->capitulo, $a->seccion])->filter()->map(fn ($s) => "«{$s}»")->implode(' > ') ?: 'Fragmento');

        // Para un apartado se antepone el encabezado del artículo, marcado como tal
        // para que el modelo no lo confunda con el contenido aplicable.
        $texto = $a->contexto
            ? "(Encabezado del artículo: {$a->contexto})\n{$a->contenido}"
            : $a->contenido;

        return "[{$n}] {$doc->titulo} [{$tipo}] ({$ambito}, {$doc->anio}) — {$ruta}\n{$texto}";
    }
}
