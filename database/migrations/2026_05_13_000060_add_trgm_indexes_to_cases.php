<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        // These run CONCURRENTLY so the table stays readable while indexing.
        // Each statement must be outside a transaction — Laravel wraps migrations,
        // so we use raw DB calls with wrapInTransaction disabled.
        $indexes = [
            'idx_cases_court_trgm'           => 'court',
            'idx_cases_chamber_trgm'         => 'chamber',
            'idx_cases_category_trgm'        => 'category',
            'idx_cases_dispute_subject_trgm' => 'dispute_subject',
            'idx_cases_result_trgm'          => 'result',
        ];

        foreach ($indexes as $name => $col) {
            DB::connection('pgvector')->statement(
                "CREATE INDEX IF NOT EXISTS {$name} ON cases USING gin ({$col} gin_trgm_ops)"
            );
        }
    }

    public function down(): void
    {
        foreach ([
            'idx_cases_court_trgm',
            'idx_cases_chamber_trgm',
            'idx_cases_category_trgm',
            'idx_cases_dispute_subject_trgm',
            'idx_cases_result_trgm',
        ] as $name) {
            DB::connection('pgvector')->statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
