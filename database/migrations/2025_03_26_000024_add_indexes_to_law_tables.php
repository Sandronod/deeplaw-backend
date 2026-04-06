<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        // ── laws ──────────────────────────────────────────────────────────────

        Schema::connection('pgvector')->table('laws', function (Blueprint $table) {
            $table->index('status',             'laws_status_idx');
            $table->index('category',           'laws_category_idx');
            $table->index('current_version_id', 'laws_current_version_id_idx');
        });

        // Trigram index on lower(title) for normalized ILIKE title matching
        DB::connection('pgvector')->statement('
            CREATE INDEX laws_title_trgm
            ON laws
            USING gin(lower(title) gin_trgm_ops)
        ');

        // ── law_articles ──────────────────────────────────────────────────────

        Schema::connection('pgvector')->table('law_articles', function (Blueprint $table) {
            $table->index(['law_id', 'chunk_index'],         'law_articles_law_chunk_idx');
            $table->index(['law_version_id', 'chunk_index'], 'law_articles_version_chunk_idx');
        });

        // Trigram index on article_num for ILIKE "მუხლი 37" searches
        DB::connection('pgvector')->statement('
            CREATE INDEX law_articles_article_num_trgm
            ON law_articles
            USING gin(article_num gin_trgm_ops)
        ');

        // ── law_versions ──────────────────────────────────────────────────────

        Schema::connection('pgvector')->table('law_versions', function (Blueprint $table) {
            $table->index(['law_id', 'is_current'], 'law_versions_law_current_idx');
            $table->index('version_date',           'law_versions_date_idx');
        });
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS law_articles_article_num_trgm');
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS laws_title_trgm');

        Schema::connection('pgvector')->table('law_versions', function (Blueprint $table) {
            $table->dropIndex('law_versions_law_current_idx');
            $table->dropIndex('law_versions_date_idx');
        });

        Schema::connection('pgvector')->table('law_articles', function (Blueprint $table) {
            $table->dropIndex('law_articles_law_chunk_idx');
            $table->dropIndex('law_articles_version_chunk_idx');
        });

        Schema::connection('pgvector')->table('laws', function (Blueprint $table) {
            $table->dropIndex('laws_status_idx');
            $table->dropIndex('laws_category_idx');
            $table->dropIndex('laws_current_version_id_idx');
        });
    }
};
