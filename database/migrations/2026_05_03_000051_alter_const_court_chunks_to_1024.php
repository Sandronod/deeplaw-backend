<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function connection(): string { return 'pgvector'; }

    public function up(): void
    {
        $pdo = DB::connection('pgvector');

        $pdo->statement('DROP INDEX IF EXISTS idx_ccch_embedding');
        $pdo->statement('ALTER TABLE const_court_chunks ALTER COLUMN embedding TYPE vector(1024)');
        $pdo->statement('CREATE INDEX idx_ccch_embedding ON const_court_chunks USING ivfflat(embedding vector_cosine_ops) WITH (lists = 50)');
    }

    public function down(): void
    {
        $pdo = DB::connection('pgvector');

        $pdo->statement('DROP INDEX IF EXISTS idx_ccch_embedding');
        $pdo->statement('ALTER TABLE const_court_chunks ALTER COLUMN embedding TYPE vector(3072)');
        $pdo->statement('CREATE INDEX idx_ccch_embedding ON const_court_chunks USING hnsw ((embedding::halfvec(3072)) halfvec_cosine_ops) WITH (m=16, ef_construction=64)');
    }
};
