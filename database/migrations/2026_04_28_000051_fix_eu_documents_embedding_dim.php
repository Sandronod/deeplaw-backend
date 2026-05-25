<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->unprepared("
            DROP INDEX IF EXISTS idx_eu_documents_embedding;

            ALTER TABLE eu_documents
                ALTER COLUMN embedding TYPE vector(1024)
                USING NULL;

            CREATE INDEX IF NOT EXISTS idx_eu_documents_embedding
                ON eu_documents USING hnsw ((embedding::halfvec(1024)) halfvec_cosine_ops)
                WITH (m = 16, ef_construction = 64);
        ");
    }

    public function down(): void
    {
        DB::connection('pgvector')->unprepared("
            DROP INDEX IF EXISTS idx_eu_documents_embedding;

            ALTER TABLE eu_documents
                ALTER COLUMN embedding TYPE vector(3072)
                USING NULL;

            CREATE INDEX IF NOT EXISTS idx_eu_documents_embedding
                ON eu_documents USING hnsw ((embedding::halfvec(3072)) halfvec_cosine_ops)
                WITH (m = 16, ef_construction = 64);
        ");
    }
};
