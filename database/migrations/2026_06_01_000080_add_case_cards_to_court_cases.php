<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        // case_card — structured extraction (GPT-4.1-mini)
        DB::connection('pgvector')->statement('
            ALTER TABLE court_cases
            ADD COLUMN IF NOT EXISTS case_card JSONB DEFAULT NULL
        ');

        // case_embedding — bge-m3 vector over legal_issue + applied_articles
        DB::connection('pgvector')->statement('
            ALTER TABLE court_cases
            ADD COLUMN IF NOT EXISTS case_embedding vector(1024) DEFAULT NULL
        ');

        // IVFFlat index for case-level ANN search
        DB::connection('pgvector')->statement('
            CREATE INDEX IF NOT EXISTS court_cases_case_embedding_idx
            ON court_cases
            USING ivfflat (case_embedding vector_cosine_ops)
            WITH (lists = 100)
        ');
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS court_cases_case_embedding_idx');
        DB::connection('pgvector')->statement('ALTER TABLE court_cases DROP COLUMN IF EXISTS case_embedding');
        DB::connection('pgvector')->statement('ALTER TABLE court_cases DROP COLUMN IF EXISTS case_card');
    }
};
