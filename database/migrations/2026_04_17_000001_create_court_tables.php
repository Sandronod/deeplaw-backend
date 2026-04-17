<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        // Drop old denormalized table
        Schema::connection('pgvector')->dropIfExists('cases_v2');

        // ── court_cases: one row per case (metadata only) ─────────────────────
        DB::connection('pgvector')->statement('
            CREATE TABLE court_cases (
                id              BIGINT PRIMARY KEY,
                case_num        TEXT,
                dispute_subject TEXT,
                case_date       DATE,
                category        TEXT,
                result          TEXT,
                claim_type      TEXT,
                kind            TEXT,
                chamber         TEXT,
                court           TEXT,
                case_type       VARCHAR(255)
            )
        ');

        DB::connection('pgvector')->statement('
            CREATE INDEX court_cases_case_num_trgm
            ON court_cases USING gin(case_num gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX court_cases_case_date_idx ON court_cases (case_date)
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX court_cases_case_type_idx ON court_cases (case_type)
        ');

        // ── court_chunks: many rows per case (content + embedding) ────────────
        DB::connection('pgvector')->statement('
            CREATE TABLE court_chunks (
                id          BIGINT PRIMARY KEY,
                case_id     BIGINT NOT NULL REFERENCES court_cases(id),
                chunk_index INT,
                content     TEXT,
                embedding   vector(1024)
            )
        ');

        // Vector similarity index
        DB::connection('pgvector')->statement('
            CREATE INDEX court_chunks_embedding_idx
            ON court_chunks
            USING ivfflat (embedding vector_cosine_ops)
            WITH (lists = 200)
        ');

        // Full-text indexes for content search
        DB::connection('pgvector')->statement('
            CREATE INDEX court_chunks_content_trgm
            ON court_chunks USING gin(content gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX court_chunks_content_fts
            ON court_chunks
            USING gin(to_tsvector(\'simple\', coalesce(content, \'\')))
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX court_chunks_case_id_idx ON court_chunks (case_id)
        ');
    }

    public function down(): void
    {
        Schema::connection('pgvector')->dropIfExists('court_chunks');
        Schema::connection('pgvector')->dropIfExists('court_cases');
    }
};
