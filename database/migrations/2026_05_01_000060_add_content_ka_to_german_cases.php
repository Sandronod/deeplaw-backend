<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->unprepared(
            'ALTER TABLE german_cases ADD COLUMN IF NOT EXISTS content_ka TEXT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('pgvector')->unprepared(
            'ALTER TABLE german_cases DROP COLUMN IF EXISTS content_ka'
        );
    }
};
