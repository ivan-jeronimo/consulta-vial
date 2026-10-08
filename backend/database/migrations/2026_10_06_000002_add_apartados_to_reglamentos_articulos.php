<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reglamentos_articulos', function (Blueprint $table) {
            $table->string('inciso', 10)->nullable()->after('fraccion');
            // Proemio del artículo/fracción cuando el chunk es solo un apartado.
            $table->text('contexto')->nullable()->after('contenido');
        });

        // La columna generada se redefine para indexar también el contexto
        // (con menor peso que el apartado mismo).
        DB::statement('ALTER TABLE reglamentos_articulos DROP COLUMN contenido_tsv');
        DB::statement(<<<'SQL'
            ALTER TABLE reglamentos_articulos ADD COLUMN contenido_tsv tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('spanish', immutable_unaccent(coalesce(capitulo, ''))), 'C') ||
                setweight(to_tsvector('spanish', immutable_unaccent(coalesce(contexto, ''))), 'B') ||
                setweight(to_tsvector('spanish', immutable_unaccent(contenido)), 'A')
            ) STORED
        SQL);
        DB::statement('CREATE INDEX reglamentos_articulos_contenido_tsv_idx ON reglamentos_articulos USING gin (contenido_tsv)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reglamentos_articulos DROP COLUMN contenido_tsv');
        DB::statement(<<<'SQL'
            ALTER TABLE reglamentos_articulos ADD COLUMN contenido_tsv tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('spanish', immutable_unaccent(coalesce(capitulo, ''))), 'B') ||
                setweight(to_tsvector('spanish', immutable_unaccent(contenido)), 'A')
            ) STORED
        SQL);
        DB::statement('CREATE INDEX reglamentos_articulos_contenido_tsv_idx ON reglamentos_articulos USING gin (contenido_tsv)');

        Schema::table('reglamentos_articulos', function (Blueprint $table) {
            $table->dropColumn(['inciso', 'contexto']);
        });
    }
};
