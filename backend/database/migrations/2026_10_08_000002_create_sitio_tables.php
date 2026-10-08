<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contenido del sitio de la comunidad (Andantes del Asfalto). El chat vial
 * (frontend/) no lo usa: queda sin marca para venderse a gobiernos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pares clave/valor editables desde el panel (seguidores, enlaces, lema…).
        Schema::create('ajustes_sitio', function (Blueprint $table) {
            $table->string('clave', 80)->primary();
            $table->string('etiqueta', 150);
            $table->text('valor')->nullable();
            $table->timestamps();
        });

        Schema::create('videos_destacados', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 200);
            $table->string('url', 500);                    // publicación o reel de Facebook
            $table->string('miniatura')->nullable();       // ruta en el disco public
            $table->unsignedBigInteger('vistas')->nullable();
            $table->date('publicado_el')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('visible')->default(true);
            $table->timestamps();
        });

        Schema::create('dinamicas', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->string('premio', 200)->nullable();
            $table->string('imagen')->nullable();
            $table->string('url_publicacion', 500)->nullable();
            $table->date('inicia_el')->nullable();
            $table->date('termina_el')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index('termina_el');
        });

        // Solo se publican ganadores que aceptaron aparecer en el sitio.
        Schema::create('ganadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dinamica_id')->nullable()->constrained('dinamicas')->nullOnDelete();
            $table->string('nombre', 150);
            $table->string('premio', 200)->nullable();     // si es distinto al de la dinámica
            $table->string('foto')->nullable();
            $table->date('fecha')->nullable();
            $table->boolean('consentimiento')->default(false);
            $table->timestamps();
        });

        Schema::create('colaboraciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->string('logo')->nullable();
            $table->string('url', 500)->nullable();
            $table->date('fecha')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('visible')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaboraciones');
        Schema::dropIfExists('ganadores');
        Schema::dropIfExists('dinamicas');
        Schema::dropIfExists('videos_destacados');
        Schema::dropIfExists('ajustes_sitio');
    }
};
