<?php

namespace App\Services\Embeddings;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiEmbeddings implements EmbeddingProvider
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private int $dimensions,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('Falta GEMINI_API_KEY.');
        }
    }

    public function embed(array $textos, bool $consulta = false): array
    {
        $modelo = "models/{$this->model}";

        // La pregunta de un usuario no puede esperar minutos a que se libere la
        // cuota: un solo intento corto, y el buscador sigue solo con texto.
        $peticion = $consulta
            ? Http::withHeaders(['x-goog-api-key' => $this->apiKey])->timeout(10)->throw()
            : Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout(120)
                ->retry(6, fn (int $intento, ?\Throwable $e) => $this->espera($intento, $e), fn ($e) => $this->esReintentable($e), throw: true);

        $respuesta = $peticion
            ->post("{$this->baseUrl}/{$modelo}:batchEmbedContents", [
                'requests' => array_map(fn (string $texto) => [
                    'model' => $modelo,
                    'content' => ['parts' => [['text' => $texto]]],
                    'taskType' => $consulta ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT',
                    'outputDimensionality' => $this->dimensions,
                ], $textos),
            ]);

        return array_column($respuesta->json('embeddings'), 'values');
    }

    public function modelo(): string
    {
        return $this->model;
    }

    /**
     * Ante un 429 Google indica cuánto esperar (RetryInfo.retryDelay, p. ej. "36s");
     * en el nivel gratuito la ventana es de un minuto y el backoff exponencial
     * corto agotaría los reintentos antes de que se libere la cuota.
     */
    private function espera(int $intento, ?\Throwable $e): int
    {
        $indicado = $e instanceof RequestException
            ? collect($e->response->json('error.details', []))
                ->firstWhere('@type', 'type.googleapis.com/google.rpc.RetryInfo')['retryDelay'] ?? null
            : null;

        return $indicado
            ? (int) (min((float) rtrim($indicado, 's'), 120) * 1000) + 1000
            : 1000 * 2 ** $intento;
    }

    private function esReintentable(\Throwable $e): bool
    {
        return ! $e instanceof \Illuminate\Http\Client\RequestException
            || in_array($e->response->status(), [429, 500, 502, 503, 504], true);
    }
}
