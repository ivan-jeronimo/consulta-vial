<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        // unaccent() no es IMMUTABLE y no puede usarse en una columna generada;
        // este envoltorio fija el diccionario y sí lo es.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        // Búsqueda léxica en español (stemming + sin acentos) para complementar
        // la vectorial: términos legales exactos como "flecha", "vuelta continua".
        DB::statement(<<<'SQL'
            ALTER TABLE reglamentos_articulos ADD COLUMN contenido_tsv tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('spanish', immutable_unaccent(coalesce(capitulo, ''))), 'B') ||
                setweight(to_tsvector('spanish', immutable_unaccent(contenido)), 'A')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX reglamentos_articulos_contenido_tsv_idx ON reglamentos_articulos USING gin (contenido_tsv)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reglamentos_articulos DROP COLUMN IF EXISTS contenido_tsv');
        DB::statement('DROP FUNCTION IF EXISTS immutable_unaccent(text)');
    }
};
