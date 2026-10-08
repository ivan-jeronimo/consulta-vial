<?php

namespace App\Services\Embeddings;

/**
 * Vectores deterministas derivados del hash del texto. Sin semántica: solo
 * sirve para probar el pipeline de ingesta en local sin gastar API.
 */
class FakeEmbeddings implements EmbeddingProvider
{
    public function __construct(private int $dimensions) {}

    public function embed(array $textos, bool $consulta = false): array
    {
        return array_map(function (string $texto) {
            mt_srand(crc32($texto));
            $vector = array_map(fn () => mt_rand() / mt_getrandmax() * 2 - 1, range(1, $this->dimensions));
            $norma = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector))) ?: 1;

            return array_map(fn ($x) => $x / $norma, $vector);
        }, $textos);
    }

    public function modelo(): string
    {
        return 'fake';
    }
}
