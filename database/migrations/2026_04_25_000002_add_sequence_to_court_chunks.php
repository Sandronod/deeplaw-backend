<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        // Start sequence after current max id
        $max = DB::connection('pgvector')->table('court_chunks')->max('id') ?? 0;
        $start = $max + 1;

        DB::connection('pgvector')->statement("
            CREATE SEQUENCE court_chunks_id_seq START WITH {$start}
        ");

        DB::connection('pgvector')->statement("
            ALTER TABLE court_chunks ALTER COLUMN id SET DEFAULT nextval('court_chunks_id_seq')
        ");
    }

    public function down(): void
    {
        DB::connection('pgvector')->statement("
            ALTER TABLE court_chunks ALTER COLUMN id DROP DEFAULT
        ");
        DB::connection('pgvector')->statement("
            DROP SEQUENCE IF EXISTS court_chunks_id_seq
        ");
    }
};
