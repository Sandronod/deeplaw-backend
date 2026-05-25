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

        DB::connection('pgvector')->statement("
            ALTER TABLE cases
            ADD COLUMN IF NOT EXISTS case_type VARCHAR(30) NOT NULL DEFAULT 'administrative'
        ");

        DB::connection('pgvector')->statement("
            CREATE INDEX IF NOT EXISTS idx_cases_case_type
            ON cases (case_type)
        ");
    }

    public function down(): void
    {
        if (! Schema::connection('pgvector')->hasTable('cases')) {
            return;
        }
        DB::connection('pgvector')->statement('DROP INDEX IF EXISTS idx_cases_case_type');
        DB::connection('pgvector')->statement('ALTER TABLE cases DROP COLUMN IF EXISTS case_type');
    }
};
