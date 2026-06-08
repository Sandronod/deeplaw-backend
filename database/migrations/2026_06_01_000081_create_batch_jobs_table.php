<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->statement('
            CREATE TABLE IF NOT EXISTS batch_jobs (
                id          SERIAL PRIMARY KEY,
                batch_id    TEXT NOT NULL UNIQUE,
                type        TEXT NOT NULL DEFAULT \'case_cards\',
                status      TEXT NOT NULL DEFAULT \'submitted\',
                input_file  TEXT,
                output_file TEXT,
                total       INT,
                succeeded   INT DEFAULT 0,
                failed      INT DEFAULT 0,
                submitted_at TIMESTAMPTZ DEFAULT NOW(),
                completed_at TIMESTAMPTZ
            )
        ');
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement('DROP TABLE IF EXISTS batch_jobs');
    }
};
