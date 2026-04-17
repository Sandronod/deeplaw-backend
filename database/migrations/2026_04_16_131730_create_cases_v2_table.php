<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->statement('
            CREATE TABLE cases_v2 (
                id            BIGSERIAL PRIMARY KEY,
                case_id       BIGINT,
                case_num      TEXT,
                dispute_subject TEXT,
                case_date     DATE,
                category      TEXT,
                result        TEXT,
                claim_type    TEXT,
                kind          TEXT,
                chamber       TEXT,
                court         TEXT,
                content       TEXT,
                embedding     vector(1024),
                meta          JSONB,
                case_type     VARCHAR(255)
            )
        ');

        // Vector index (ivfflat — 1024 dims fits perfectly)
        DB::connection('pgvector')->statement('
            CREATE INDEX cases_v2_embedding_idx
            ON cases_v2
            USING ivfflat (embedding vector_cosine_ops)
            WITH (lists = 200)
        ');

        // Trigram indexes for text search
        DB::connection('pgvector')->statement('
            CREATE INDEX cases_v2_content_trgm
            ON cases_v2
            USING gin(content gin_trgm_ops)
        ');

        DB::connection('pgvector')->statement('
            CREATE INDEX cases_v2_case_num_trgm
            ON cases_v2
            USING gin(case_num gin_trgm_ops)
        ');

        // tsvector full-text index
        DB::connection('pgvector')->statement('
            CREATE INDEX cases_v2_content_fts
            ON cases_v2
            USING gin(to_tsvector(\'simple\', coalesce(content, \'\')))
        ');

        // Metadata indexes
        DB::connection('pgvector')->statement('
            CREATE INDEX cases_v2_case_id_idx ON cases_v2 (case_id)
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX cases_v2_case_type_idx ON cases_v2 (case_type)
        ');
    }

    public function down(): void
    {
        Schema::connection('pgvector')->dropIfExists('cases_v2');
    }
};
