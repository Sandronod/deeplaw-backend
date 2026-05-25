<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->unprepared(
            'ALTER TABLE matsne_documents ADD COLUMN IF NOT EXISTS content TEXT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('pgvector')->unprepared(
            'ALTER TABLE matsne_documents DROP COLUMN IF EXISTS content'
        );
    }
};
