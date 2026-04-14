<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->statement("
            ALTER TABLE laws
            ADD COLUMN IF NOT EXISTS content_hash VARCHAR(32) DEFAULT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement("
            ALTER TABLE laws DROP COLUMN IF EXISTS content_hash
        ");
    }
};
