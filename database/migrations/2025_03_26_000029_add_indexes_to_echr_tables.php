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
        // ── echr_cases ────────────────────────────────────────────────────────

        Schema::connection('pgvector')->table('echr_cases', function (Blueprint $table) {
            $table->index('importance',       'echr_cases_importance_idx');
            $table->index('respondent_state', 'echr_cases_respondent_idx');
            $table->index('judgment_date',    'echr_cases_judgment_date_idx');
            $table->index('status',           'echr_cases_status_idx');
        });

        // Trigram index on lower(title) for normalized ILIKE matching
        DB::connection('pgvector')->statement('
            CREATE INDEX echr_cases_title_trgm
            ON echr_cases
            USING gin(lower(title) gin_trgm_ops)
        ');

        // Trigram index on application_number — only on non-null rows
        DB::connection('pgvector')->statement('
            CREATE INDEX echr_cases_appno_trgm
            ON echr_cases
            USING gin(application_number gin_trgm_ops)
            WHERE application_number IS NOT NULL
        ');

        // NOTE: ivfflat vector index on echr_paragraphs.embedding is intentionally
        // omitted here — it requires data to be present (lists=50 needs ≥50 rows).
        // Run this manually after seeding:
        //   CREATE INDEX echr_paragraphs_embedding_idx
        //   ON echr_paragraphs
        //   USING ivfflat (embedding vector_cosine_ops)
        //   WITH (lists = 50);
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS echr_cases_appno_trgm');
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS echr_cases_title_trgm');

        Schema::connection('pgvector')->table('echr_cases', function (Blueprint $table) {
            $table->dropIndex('echr_cases_status_idx');
            $table->dropIndex('echr_cases_judgment_date_idx');
            $table->dropIndex('echr_cases_respondent_idx');
            $table->dropIndex('echr_cases_importance_idx');
        });
    }
};
