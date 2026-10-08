<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Etapas de una dinámica: lanzamiento (video_inicio), sorteo en vivo y ganadores (video_cierre).
        Schema::table('dinamicas', function (Blueprint $table) {
            $table->foreignId('video_en_vivo_id')->nullable()->after('video_inicio_id')->constrained('videos_destacados')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dinamicas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_en_vivo_id');
        });
    }
};
