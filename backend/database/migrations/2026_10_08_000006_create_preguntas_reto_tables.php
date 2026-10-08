<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Banco del reto (simulador de examen de manejo). Las genera el LLM a
        // partir de la guía y los reglamentos; solo se juegan las aprobadas.
        Schema::create('preguntas_reto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documento_id')->nullable()->constrained('documentos_reglamentos')->nullOnDelete();
            $table->jsonb('fuentes')->nullable();          // ids de reglamentos_articulos
            $table->string('categoria', 150);
            $table->text('pregunta');
            $table->jsonb('opciones');                     // 3 o 4 textos
            $table->unsignedSmallInteger('correcta');      // índice en opciones
            $table->text('explicacion')->nullable();
            $table->string('cita', 300)->nullable();
            $table->string('estado', 12)->default('borrador'); // borrador | aprobada | descartada
            $table->string('modelo', 80)->nullable();      // null = escrita a mano
            $table->timestamps();

            $table->index(['estado', 'categoria']);
        });

        // Fragmentos que ya pasaron por el generador (dieran o no preguntas),
        // para que cada corrida avance sobre material nuevo.
        Schema::create('reto_fragmentos_revisados', function (Blueprint $table) {
            $table->foreignId('articulo_id')->primary()->constrained('reglamentos_articulos')->cascadeOnDelete();
            $table->timestamp('revisado_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reto_fragmentos_revisados');
        Schema::dropIfExists('preguntas_reto');
    }
};
