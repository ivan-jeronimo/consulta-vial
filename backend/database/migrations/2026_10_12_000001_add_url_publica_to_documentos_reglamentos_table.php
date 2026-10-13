<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_reglamentos', function (Blueprint $table) {
            // Dónde puede leerlo cualquiera (Periódico Oficial, sitio del gobierno):
            // el chat enlaza ahí desde cada artículo citado.
            $table->string('url_publica', 2048)->nullable()->after('fecha_publicacion');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_reglamentos', function (Blueprint $table) {
            $table->dropColumn('url_publica');
        });
    }
};
