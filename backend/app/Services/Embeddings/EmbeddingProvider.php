<?php

namespace App\Services\Embeddings;

interface EmbeddingProvider
{
    /**
     * @param  list<string>  $textos  Un lote (ya acotado por batch_size).
     * @param  bool  $consulta  true para la pregunta del usuario, false para fragmentos indexados.
     * @return list<list<float>>  Un vector por texto, en el mismo orden.
     */
    public function embed(array $textos, bool $consulta = false): array;

    public function modelo(): string;
}
