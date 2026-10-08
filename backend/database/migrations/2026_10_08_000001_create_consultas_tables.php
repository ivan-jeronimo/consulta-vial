<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preguntas agrupadas por intención: "¿puedo girar con flecha roja?" y
        // "vuelta a la derecha en rojo" son el mismo tema.
        Schema::create('temas_consulta', function (Blueprint $table) {
            $table->id();
            $table->string('etiqueta', 500); // editable desde el admin
            $table->vector('embedding', dimensions: config('rag.embeddings.dimensions'));
            $table->timestamps();

            $table->vectorIndex('embedding');
        });

        // Registro anónimo: sin IP ni datos personales. `visitante` es un
        // identificador aleatorio del navegador, solo para contar usuarios únicos.
        Schema::create('consultas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('visitante')->nullable();
            $table->text('pregunta');
            $table->foreignId('estado_id')->nullable()->constrained('estados')->nullOnDelete();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();
            $table->foreignId('tema_id')->nullable()->constrained('temas_consulta')->nullOnDelete();

            $table->boolean('respondida')->default(false); // el LLM redactó respuesta
            $table->boolean('cubierto')->nullable();        // los documentos trataban el tema
            $table->string('modelo', 80)->nullable();
            $table->jsonb('fuentes')->nullable();           // ids de reglamentos_articulos citados
            $table->jsonb('documentos')->nullable();        // ids de documentos citados
            $table->unsignedInteger('latencia_ms')->nullable();
            $table->smallInteger('valoracion')->nullable();  // 1 útil, -1 no útil

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('valorada_at')->nullable();

            $table->index('created_at');
            $table->index(['tema_id', 'created_at']);
            $table->index(['estado_id', 'municipio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
        Schema::dropIfExists('temas_consulta');
    }
};
