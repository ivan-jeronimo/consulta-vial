<?php

namespace App\Jobs;

use App\Models\DocumentoReglamento;
use App\Services\Reto\GeneradorPreguntas;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerarPreguntasRetoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $documentoId,
        public readonly ?string $capitulo = null,
        public readonly int $lotes = 3,
    ) {}

    public function handle(GeneradorPreguntas $generador): void
    {
        $generador->generar(DocumentoReglamento::findOrFail($this->documentoId), $this->capitulo, $this->lotes);
    }
}
