<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personas que entran al reto con Facebook. Solo lo que da el login:
        // id (por app), nombre, correo y foto.
        Schema::create('jugadores', function (Blueprint $table) {
            $table->id();
            $table->string('facebook_id', 40)->unique();
            $table->string('nombre', 150);
            $table->string('email')->nullable();
            $table->string('foto')->nullable();            // ruta en el disco public
            $table->boolean('publico')->nullable();        // aparecer en el ranking; null = aún no decide
            $table->rememberToken();
            $table->timestamp('ultimo_acceso_at')->nullable();
            $table->timestamps();
        });

        Schema::create('partidas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('jugador_id')->constrained('jugadores')->cascadeOnDelete();
            // [{id: pregunta, orden: [índices de opciones en el orden mostrado]}]
            $table->jsonb('preguntas');
            $table->unsignedSmallInteger('actual')->default(0);
            $table->timestamp('mostrada_at', 3)->nullable(); // cuándo se mostró la pregunta actual (mide el tiempo)
            $table->unsignedInteger('puntos')->default(0);
            $table->unsignedSmallInteger('aciertos')->default(0);
            $table->timestamp('terminada_at')->nullable();
            $table->timestamps();

            $table->index(['terminada_at', 'jugador_id', 'puntos']);
        });

        Schema::create('respuestas_reto', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('partida_id')->constrained('partidas')->cascadeOnDelete();
            $table->foreignId('pregunta_id')->nullable()->constrained('preguntas_reto')->nullOnDelete();
            $table->unsignedSmallInteger('opcion')->nullable(); // índice original; null = se acabó el tiempo
            $table->boolean('correcta');
            $table->unsignedInteger('ms');
            $table->unsignedSmallInteger('puntos');
            $table->timestamp('created_at')->useCurrent();

            // Para ver qué preguntas fallan más (preguntas confusas o temas a reforzar).
            $table->index(['pregunta_id', 'correcta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respuestas_reto');
        Schema::dropIfExists('partidas');
        Schema::dropIfExists('jugadores');
    }
};
