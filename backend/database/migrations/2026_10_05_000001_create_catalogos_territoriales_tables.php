<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo INEGI de entidades federativas (32).
        Schema::create('estados', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->char('clave_inegi', 2)->unique();
            $table->string('nombre', 80);
            $table->string('slug', 80)->unique();
            $table->timestamps();
        });

        Schema::create('municipios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estado_id')->constrained('estados')->restrictOnDelete();
            $table->char('clave_inegi', 3)->nullable();
            $table->string('nombre', 150);
            $table->string('slug', 150);
            $table->timestamps();

            $table->unique(['estado_id', 'slug']);
            $table->unique(['estado_id', 'clave_inegi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
        Schema::dropIfExists('estados');
    }
};
