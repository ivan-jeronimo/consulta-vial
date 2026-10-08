<?php

namespace App\Services\Embeddings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiEmbeddings implements EmbeddingProvider
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private int $dimensions,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('Falta OPENAI_API_KEY.');
        }
    }

    public function embed(array $textos, bool $consulta = false): array
    {
        // Bearer para OpenAI; "api-key" para Azure OpenAI / Foundry (OPENAI_BASE_URL
        // apuntando a https://<recurso>.openai.azure.com/openai/v1). Cada uno ignora el otro.
        $respuesta = Http::withToken($this->apiKey)
            ->withHeaders(['api-key' => $this->apiKey])
            ->timeout(120)
            ->retry(5, fn (int $intento) => 1000 * 2 ** $intento, fn ($e) => $this->esReintentable($e), throw: true)
            ->post("{$this->baseUrl}/embeddings", [
                'model' => $this->model,
                'input' => $textos,
                'dimensions' => $this->dimensions,
                'encoding_format' => 'float',
            ]);

        return collect($respuesta->json('data'))
            ->sortBy('index')
            ->pluck('embedding')
            ->values()
            ->all();
    }

    public function modelo(): string
    {
        return $this->model;
    }

    private function esReintentable(\Throwable $e): bool
    {
        return ! $e instanceof \Illuminate\Http\Client\RequestException
            || in_array($e->response->status(), [429, 500, 502, 503, 504], true);
    }
}
