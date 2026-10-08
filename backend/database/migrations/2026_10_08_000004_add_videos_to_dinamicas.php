<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada dinámica se anuncia con un video y se cierra con otro (entrega del premio).
        Schema::table('dinamicas', function (Blueprint $table) {
            $table->foreignId('video_inicio_id')->nullable()->constrained('videos_destacados')->nullOnDelete();
            $table->foreignId('video_cierre_id')->nullable()->constrained('videos_destacados')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dinamicas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_inicio_id');
            $table->dropConstrainedForeignId('video_cierre_id');
        });
    }
};
