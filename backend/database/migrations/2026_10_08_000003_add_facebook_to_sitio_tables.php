<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Los videos llegan solos de la página y se ordenan por reproducciones;
        // desde el panel solo se fijan arriba o se ocultan.
        Schema::table('videos_destacados', function (Blueprint $table) {
            $table->string('facebook_id', 40)->nullable()->unique();
            $table->string('tipo', 10)->default('video'); // reel | video
            $table->boolean('fijado')->default(false);
            $table->dropColumn('orden');

            $table->index(['visible', 'fijado', 'vistas']);
        });

        Schema::table('dinamicas', function (Blueprint $table) {
            $table->string('facebook_post_id', 60)->nullable()->unique();
        });

        // Bandeja de publicaciones con #dinamica: una dinámica tiene anuncio,
        // recordatorios y entrega; aquí se elige cuál la crea y el resto se ignora.
        Schema::create('publicaciones_facebook', function (Blueprint $table) {
            $table->id();
            $table->string('facebook_id', 60)->unique();
            $table->text('mensaje')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('imagen')->nullable();
            $table->timestamp('publicada_at')->nullable();
            $table->string('estado', 12)->default('pendiente'); // pendiente | usada | ignorada
            $table->foreignId('dinamica_id')->nullable()->constrained('dinamicas')->nullOnDelete();
            $table->timestamps();

            $table->index(['estado', 'publicada_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicaciones_facebook');

        Schema::table('dinamicas', function (Blueprint $table) {
            $table->dropColumn('facebook_post_id');
        });

        Schema::table('videos_destacados', function (Blueprint $table) {
            $table->dropIndex(['visible', 'fijado', 'vistas']);
            $table->dropColumn(['facebook_id', 'tipo', 'fijado']);
            $table->unsignedSmallInteger('orden')->default(0);
        });
    }
};
