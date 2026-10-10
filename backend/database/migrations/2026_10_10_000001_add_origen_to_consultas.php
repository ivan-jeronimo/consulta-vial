<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sitio que incrustó el chat (p. ej. "andantes"); null = Consulta Vial directo.
        Schema::table('consultas', function (Blueprint $table) {
            $table->string('origen', 40)->nullable()->after('visitante');
            $table->index(['origen', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $table) {
            $table->dropIndex(['origen', 'created_at']);
            $table->dropColumn('origen');
        });
    }
};
