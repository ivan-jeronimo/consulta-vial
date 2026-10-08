<?php

namespace App\Providers;

use App\Services\Embeddings\EmbeddingProvider;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Embeddings\FakeEmbeddings;
use App\Services\Embeddings\GeminiEmbeddings;
use App\Services\Embeddings\OpenAiEmbeddings;
use App\Services\Facebook\GraphFacebook;
use App\Services\Ingesta\SegmentadorArticulos;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EmbeddingProvider::class, function () {
            $config = config('rag.embeddings');

            return match ($config['provider']) {
                'openai' => new OpenAiEmbeddings(
                    (string) $config['openai']['key'], $config['openai']['url'], $config['model'], $config['dimensions'],
                ),
                'gemini' => new GeminiEmbeddings(
                    (string) $config['gemini']['key'], $config['gemini']['url'], $config['model'], $config['dimensions'],
                ),
                'fake' => new FakeEmbeddings($config['dimensions']),
                default => throw new InvalidArgumentException("Proveedor de embeddings desconocido: {$config['provider']}"),
            };
        });

        $this->app->bind(EmbeddingService::class, fn ($app) => new EmbeddingService(
            $app->make(EmbeddingProvider::class),
            config('rag.embeddings.batch_size'),
            config('rag.embeddings.dimensions'),
            $app->make('cache')->store(),
            config('rag.embeddings.textos_por_minuto'),
        ));

        $this->app->bind(GraphFacebook::class, fn () => GraphFacebook::desdeConfig());

        $this->app->bind(SegmentadorArticulos::class, fn () => new SegmentadorArticulos(
            config('rag.ingesta.max_caracteres_chunk'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
