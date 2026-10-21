<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_reglamentos', function (Blueprint $table) {
            // Quién lo emite (no se deduce del nivel: una entidad tiene varias dependencias)
            // y dónde se publicó oficialmente. Van en la cita de las fuentes.
            $table->string('emisor')->nullable()->after('titulo');
            $table->string('publicado_en')->nullable()->after('url_publica');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_reglamentos', function (Blueprint $table) {
            $table->dropColumn(['emisor', 'publicado_en']);
        });
    }
};
