<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_reglamentos', function (Blueprint $table) {
            // reglamento | ley | manual | otro
            $table->string('tipo', 12)->default('reglamento')->after('titulo');
        });

        DB::statement("ALTER TABLE documentos_reglamentos ADD CONSTRAINT doc_reg_tipo_chk CHECK (tipo IN ('reglamento', 'ley', 'manual', 'otro'))");
    }

    public function down(): void
    {
        Schema::table('documentos_reglamentos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
