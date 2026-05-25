<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function connection(): string { return 'pgvector'; }

    public function up(): void
    {
        DB::connection('pgvector')->statement(
            'ALTER TABLE matsne_documents ALTER COLUMN title TYPE TEXT'
        );
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement(
            'ALTER TABLE matsne_documents ALTER COLUMN title TYPE VARCHAR(1000)'
        );
    }
};
