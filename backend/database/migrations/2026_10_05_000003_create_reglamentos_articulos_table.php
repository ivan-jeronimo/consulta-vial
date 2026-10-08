<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('reglamentos_articulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documento_id')->constrained('documentos_reglamentos')->cascadeOnDelete();

            // Llaves territoriales heredadas del documento (desnormalizadas para
            // filtrar la búsqueda vectorial sin JOIN).
            $table->string('nivel', 10);
            $table->foreignId('estado_id')->nullable()->constrained('estados')->restrictOnDelete();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->restrictOnDelete();

            // Ubicación jerárquica dentro del documento
            $table->string('titulo')->nullable();
            $table->string('capitulo')->nullable();
            $table->string('seccion')->nullable();
            $table->string('articulo', 40)->nullable();   // "15", "15 Bis", "PRIMERO" (transitorio)
            $table->string('fraccion', 40)->nullable();   // solo si el artículo se subdividió
            $table->unsignedInteger('orden');

            $table->text('contenido');
            $table->unsignedInteger('tokens_aprox')->default(0);
            $table->vector('embedding', dimensions: config('rag.embeddings.dimensions'));
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['estado_id', 'municipio_id', 'nivel'], 'reg_art_territorio_idx');
            $table->index(['documento_id', 'orden']);
            $table->vectorIndex('embedding'); // HNSW + vector_cosine_ops
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglamentos_articulos');
    }
};
