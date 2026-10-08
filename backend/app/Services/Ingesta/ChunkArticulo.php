<?php

namespace App\Services\Ingesta;

final readonly class ChunkArticulo
{
    public function __construct(
        public int $orden,
        public string $contenido,
        public ?string $titulo = null,
        public ?string $capitulo = null,
        public ?string $seccion = null,
        public ?string $articulo = null,
        public ?string $fraccion = null,
        public ?string $inciso = null,
        // Proemio del artículo (y de la fracción) cuando el chunk es solo un apartado.
        public ?string $contexto = null,
    ) {}

    /**
     * Texto que se envía al modelo de embeddings: el contenido precedido por su
     * ruta jerárquica y el encabezado del artículo, para que "b) Frente a una
     * flecha verde…" no quede huérfano de contexto.
     */
    public function textoParaEmbedding(string $tituloDocumento): string
    {
        $ruta = array_filter([
            $tituloDocumento,
            $this->titulo,
            $this->capitulo,
            $this->seccion,
            $this->articulo ? "Artículo {$this->articulo}" : null,
            $this->fraccion ? "Fracción {$this->fraccion}" : null,
            $this->inciso ? "Inciso {$this->inciso})" : null,
        ]);

        return implode(' > ', $ruta)."\n\n".($this->contexto ? "{$this->contexto}\n…\n" : '').$this->contenido;
    }

    public function tokensAproximados(): int
    {
        // ~4 caracteres por token en español es suficiente para control de lotes.
        return (int) ceil(mb_strlen($this->contexto.$this->contenido) / 4);
    }
}
