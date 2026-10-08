<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_reglamentos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');

            // Jurisdicción territorial
            $table->string('nivel', 10); // federal | estatal | municipal
            $table->foreignId('estado_id')->nullable()->constrained('estados')->restrictOnDelete();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->restrictOnDelete();

            // Vigencia jurídica (independiente del procesamiento)
            $table->smallInteger('anio');
            $table->date('fecha_publicacion')->nullable();
            $table->string('estatus_vigencia', 10)->default('vigente'); // vigente | derogado
            $table->foreignId('reemplaza_a_id')->nullable()
                ->constrained('documentos_reglamentos')->nullOnDelete();

            // Archivo fuente
            $table->string('archivo_path');
            $table->string('archivo_nombre_original')->nullable();
            $table->string('archivo_mime', 120);
            $table->char('archivo_sha256', 64);

            // Estado de procesamiento (pipeline de ingesta)
            $table->string('estatus_procesamiento', 12)->default('pendiente'); // pendiente | procesando | completado | error
            $table->unsignedInteger('chunks_count')->default(0);
            $table->string('embedding_modelo', 80)->nullable();
            $table->text('error_mensaje')->nullable();
            $table->timestamp('procesado_at')->nullable();

            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['nivel', 'estado_id', 'municipio_id', 'estatus_vigencia'], 'doc_reg_territorio_idx');
            $table->index('estatus_procesamiento');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documentos_reglamentos
                ADD CONSTRAINT doc_reg_nivel_chk CHECK (nivel IN ('federal', 'estatal', 'municipal')),
                ADD CONSTRAINT doc_reg_vigencia_chk CHECK (estatus_vigencia IN ('vigente', 'derogado')),
                ADD CONSTRAINT doc_reg_procesamiento_chk CHECK (estatus_procesamiento IN ('pendiente', 'procesando', 'completado', 'error')),
                ADD CONSTRAINT doc_reg_territorio_chk CHECK (
                    (nivel = 'federal'   AND estado_id IS NULL     AND municipio_id IS NULL) OR
                    (nivel = 'estatal'   AND estado_id IS NOT NULL AND municipio_id IS NULL) OR
                    (nivel = 'municipal' AND estado_id IS NOT NULL AND municipio_id IS NOT NULL)
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_reglamentos');
    }
};
