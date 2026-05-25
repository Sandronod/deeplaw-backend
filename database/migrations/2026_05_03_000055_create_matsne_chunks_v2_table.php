<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->unprepared('
            CREATE TABLE IF NOT EXISTS matsne_chunks_v2 (
                id               BIGSERIAL PRIMARY KEY,
                document_id      BIGINT       NOT NULL REFERENCES matsne_documents(id) ON DELETE CASCADE,
                matsne_id        BIGINT       NOT NULL,
                title            TEXT,
                doc_type         VARCHAR(100),
                issuer           VARCHAR(300),
                is_active        BOOLEAN      NOT NULL DEFAULT TRUE,
                effective_from_year SMALLINT,
                effective_to_year   SMALLINT,
                chunk_index      SMALLINT     NOT NULL,
                content          TEXT         NOT NULL,
                embedding        vector(1024),
                created_at       TIMESTAMP,
                updated_at       TIMESTAMP
            )
        ');

        DB::connection('pgvector')->unprepared('
            CREATE INDEX IF NOT EXISTS matsne_chunks_v2_document_id_chunk_index
                ON matsne_chunks_v2 (document_id, chunk_index)
        ');

        DB::connection('pgvector')->unprepared('
            CREATE INDEX IF NOT EXISTS matsne_chunks_v2_matsne_id
                ON matsne_chunks_v2 (matsne_id)
        ');

        DB::connection('pgvector')->unprepared('
            CREATE INDEX IF NOT EXISTS matsne_chunks_v2_doc_type
                ON matsne_chunks_v2 (doc_type)
        ');

        DB::connection('pgvector')->unprepared('
            CREATE INDEX IF NOT EXISTS matsne_chunks_v2_is_active
                ON matsne_chunks_v2 (is_active)
        ');

        DB::connection('pgvector')->unprepared('
            CREATE INDEX IF NOT EXISTS matsne_chunks_v2_effective_years
                ON matsne_chunks_v2 (effective_from_year, effective_to_year)
        ');

        DB::connection('pgvector')->unprepared('
            CREATE INDEX IF NOT EXISTS matsne_chunks_v2_content_trgm
                ON matsne_chunks_v2 USING GIN (content gin_trgm_ops)
        ');

        // HNSW index for fast approximate nearest-neighbour search
        DB::connection('pgvector')->unprepared('
            CREATE INDEX IF NOT EXISTS matsne_chunks_v2_embedding_hnsw
                ON matsne_chunks_v2 USING hnsw (embedding vector_cosine_ops)
                WITH (m = 16, ef_construction = 64)
        ');
    }

    public function down(): void
    {
        DB::connection('pgvector')->unprepared('DROP TABLE IF EXISTS matsne_chunks_v2');
    }
};
