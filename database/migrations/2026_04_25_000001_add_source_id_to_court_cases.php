<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        // source_id — original CaseID from SQL Server (used for citation URLs)
        DB::connection('pgvector')->statement('
            ALTER TABLE court_cases ADD COLUMN source_id BIGINT
        ');

        // Populate source_id for existing civil/administrative cases
        DB::connection('pgvector')->statement('
            UPDATE court_cases SET source_id = id
        ');

        // Sequence for new auto-generated IDs (criminal, matsne, ...)
        DB::connection('pgvector')->statement('
            CREATE SEQUENCE court_cases_id_seq START WITH 100000
        ');

        // Prevent duplicate (source_id + case_type) combinations
        DB::connection('pgvector')->statement('
            ALTER TABLE court_cases
            ADD CONSTRAINT uq_source_case UNIQUE (source_id, case_type)
        ');
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement('
            ALTER TABLE court_cases DROP CONSTRAINT IF EXISTS uq_source_case
        ');
        DB::connection('pgvector')->statement('
            DROP SEQUENCE IF EXISTS court_cases_id_seq
        ');
        DB::connection('pgvector')->statement('
            ALTER TABLE court_cases DROP COLUMN IF EXISTS source_id
        ');
    }
};
