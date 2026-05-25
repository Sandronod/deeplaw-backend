<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        if (! Schema::connection('pgvector')->hasTable('cases')) {
            return;
        }

        // Drop section column (was used as chunk_index workaround)
        DB::connection('pgvector')->statement('ALTER TABLE cases DROP COLUMN IF EXISTS section');

        // Add chunk ordering index — speeds up chunksForCases() ordering
        DB::connection('pgvector')->statement('
            CREATE INDEX IF NOT EXISTS idx_cases_chunk_order
            ON cases (case_id, ((meta->>\'chunk_index\')::int))
        ');
    }

    public function down(): void
    {
        if (! Schema::connection('pgvector')->hasTable('cases')) {
            return;
        }
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_chunk_order');
        DB::connection('pgvector')->statement("ALTER TABLE cases ADD COLUMN IF NOT EXISTS section text");
    }
};
