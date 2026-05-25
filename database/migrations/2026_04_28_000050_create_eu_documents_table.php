<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->unprepared("
            CREATE TABLE IF NOT EXISTS eu_documents (
                id              BIGSERIAL PRIMARY KEY,
                cellar_id       VARCHAR(255) NOT NULL,
                doc_type        VARCHAR(50)  NOT NULL DEFAULT 'unknown',
                -- legislation: regulation, directive, decision
                -- case law:    judgment, order, opinion
                source          VARCHAR(20)  NOT NULL DEFAULT 'legislation',
                -- 'legislation' or 'case_law'
                court           VARCHAR(50)  NULL,
                -- e.g. 'Court of Justice', 'General Court'
                case_num        VARCHAR(255) NULL,
                title           TEXT         NULL,
                doc_date        DATE         NULL,
                language        VARCHAR(10)  NOT NULL DEFAULT 'en',
                content         TEXT         NOT NULL DEFAULT '',
                embedding       vector(3072) NULL,
                meta            JSONB        NOT NULL DEFAULT '{}',
                content_hash    VARCHAR(64)  NULL,
                created_at      TIMESTAMP    DEFAULT NOW(),
                updated_at      TIMESTAMP    DEFAULT NOW()
            );

            CREATE INDEX IF NOT EXISTS idx_eu_documents_cellar_id ON eu_documents (cellar_id);
            CREATE INDEX IF NOT EXISTS idx_eu_documents_doc_type  ON eu_documents (doc_type);
            CREATE INDEX IF NOT EXISTS idx_eu_documents_source    ON eu_documents (source);
            CREATE INDEX IF NOT EXISTS idx_eu_documents_meta      ON eu_documents USING GIN (meta);

            CREATE INDEX IF NOT EXISTS idx_eu_documents_embedding
                ON eu_documents USING hnsw ((embedding::halfvec(3072)) halfvec_cosine_ops)
                WITH (m = 16, ef_construction = 64);
        ");
    }

    public function down(): void
    {
        DB::connection('pgvector')->unprepared("
            DROP INDEX IF EXISTS idx_eu_documents_embedding;

            DROP INDEX IF EXISTS idx_eu_documents_meta;
            DROP INDEX IF EXISTS idx_eu_documents_source;
            DROP INDEX IF EXISTS idx_eu_documents_doc_type;
            DROP INDEX IF EXISTS idx_eu_documents_cellar_id;
            DROP TABLE IF EXISTS eu_documents;
        ");
    }
};
