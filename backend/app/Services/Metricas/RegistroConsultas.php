<?php

namespace App\Services\Metricas;

use App\Models\Consulta;
use App\Models\TemaConsulta;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Guarda cada consulta de forma anónima y la asigna a un tema: preguntas con
 * la misma intención aunque estén redactadas distinto ("¿puedo girar con
 * flecha roja?" / "vuelta a la derecha en rojo") cuentan como el mismo tema.
 */
class RegistroConsultas
{
    public function __construct(private EmbeddingService $embeddings) {}

    /** @param  array<string, mixed>  $datos  columnas de `consultas` (sin tema_id) */
    public function registrar(array $datos): Consulta
    {
        return Consulta::create([...$datos, 'tema_id' => $this->temaPara($datos['pregunta'])?->id]);
    }

    /**
     * Tema más parecido si supera el umbral de similitud; si no, uno nuevo con
     * esta pregunta como etiqueta. Sin embeddings disponibles, sin tema.
     */
    public function temaPara(string $pregunta): ?TemaConsulta
    {
        if (! $this->embeddings->esSemantico()) {
            return null;
        }

        try {
            $vector = $this->embeddings->embedConsulta($pregunta);
        } catch (\Throwable $e) {
            Log::warning('Consulta sin tema: no se obtuvo su embedding', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }

        $cercano = TemaConsulta::query()
            ->select('*')
            ->selectVectorDistance('embedding', $vector, 'distancia')
            ->orderByVectorDistance('embedding', $vector)
            ->first();

        // pgvector usa distancia coseno (1 - similitud).
        $umbral = (float) config('rag.metricas.similitud_tema');

        if ($cercano && (1 - (float) $cercano->distancia) >= $umbral) {
            return $cercano;
        }

        return DB::transaction(fn () => TemaConsulta::create([
            'etiqueta' => mb_substr(trim($pregunta), 0, 500),
            'embedding' => $vector,
        ]));
    }

    /** @return bool false si la consulta no existe o ya caducó el plazo para valorarla */
    public function valorar(string $consultaId, bool $util): bool
    {
        return (bool) Consulta::whereKey($consultaId)
            ->where('created_at', '>=', now()->subDay())
            ->update(['valoracion' => $util ? 1 : -1, 'valorada_at' => now()]);
    }
}
