<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->statement(<<<'SQL'
            UPDATE matsne_documents
            SET is_active = (matsne_id = 1043717)
            WHERE matsne_id IN (31690, 32842, 1043717)
            SQL);

        DB::connection('pgvector')->statement(<<<'SQL'
            UPDATE matsne_chunks_v2 mc
            SET is_active = (mc.matsne_id = 1043717)
            WHERE mc.matsne_id IN (31690, 32842, 1043717)
              AND mc.is_active IS DISTINCT FROM (mc.matsne_id = 1043717)
            SQL);
    }

    public function down(): void
    {
        // Data correction is intentionally irreversible.
    }
};
