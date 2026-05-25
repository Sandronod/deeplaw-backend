<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        if (! Schema::connection('pgvector')->hasTable('cases')) {
            return;
        }

        // ── case_id btree ─────────────────────────────────────────────────────
        // WHERE case_id IN (...), GROUP BY case_id, ORDER BY case_id
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cases_case_id
            ON cases USING btree (case_id)');

        // ── content GIN trgm ─────────────────────────────────────────────────
        // მოსამართლის/მხარის სახელი, ნებისმიერი ტექსტი ILIKE-ით
        // ყველაზე მნიშვნელოვანი index ამ პროექტისთვის
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cases_content_trgm
            ON cases USING gin (content gin_trgm_ops)');

        // ── case_num GIN trgm ─────────────────────────────────────────────────
        // საქმის ნომრით ძებნა: "ბს-123", "as-456" და ა.შ.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cases_case_num_trgm
            ON cases USING gin (case_num gin_trgm_ops)');

        // ── metadata სვეტები btree ────────────────────────────────────────────
        // ფილტრაცია court, chamber, category-ით (exact match და ORDER BY)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_cases_court
            ON cases USING btree (court)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_cases_chamber
            ON cases USING btree (chamber)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_cases_category
            ON cases USING btree (category)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_cases_case_date
            ON cases USING btree (case_date)');
    }

    public function down(): void
    {
        if (! Schema::connection('pgvector')->hasTable('cases')) {
            return;
        }
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_case_id');
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_content_trgm');
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_case_num_trgm');
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_court');
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_chamber');
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_category');
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_case_date');
    }
};
