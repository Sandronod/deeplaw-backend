<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        DB::connection('pgvector')->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // ── Queue: fetch progress tracker ─────────────────────────────────────
        Schema::connection('pgvector')->create('german_cases_queue', function (Blueprint $table) {
            $table->unsignedBigInteger('external_id')->primary();
            $table->string('status', 20)->default('pending'); // pending|done|failed
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('fetched_at')->nullable();

            $table->index('status');
        });

        // ── Cases: metadata + full German text ───────────────────────────────
        Schema::connection('pgvector')->create('german_cases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('external_id')->unique(); // Open Legal Data ID
            $table->string('slug', 300)->nullable();
            $table->string('file_number', 200)->nullable();      // Aktenzeichen
            $table->date('date')->nullable();                    // Entscheidungsdatum
            $table->string('decision_type', 100)->nullable();   // Urteil, Beschluss...
            $table->string('ecli', 200)->nullable();
            $table->string('court_name', 300)->nullable();
            $table->string('court_slug', 200)->nullable();
            $table->string('jurisdiction', 200)->nullable();     // Ordentliche, Verwaltung...
            $table->string('level_of_appeal', 100)->nullable(); // BGH, OLG, LG...
            $table->string('court_city', 150)->nullable();
            $table->string('court_state', 100)->nullable();
            $table->text('content')->nullable();                 // სრული გერმანული ტექსტი
            $table->string('content_hash', 64)->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index('decision_type');
            $table->index('level_of_appeal');
            $table->index('jurisdiction');
        });

        DB::connection('pgvector')->statement('
            CREATE INDEX german_cases_file_number_trgm
            ON german_cases USING gin(file_number gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX german_cases_court_name_trgm
            ON german_cases USING gin(court_name gin_trgm_ops)
        ');

        // ── Chunks: Georgian translation + embedding (created later) ──────────
        // Created empty now — populated after translation step
        Schema::connection('pgvector')->create('german_chunks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('case_id');

            // Denormalized for JOIN-free retrieval
            $table->unsignedBigInteger('external_id');
            $table->string('court_name', 300)->nullable();
            $table->string('level_of_appeal', 100)->nullable();
            $table->string('decision_type', 100)->nullable();
            $table->string('jurisdiction', 200)->nullable();
            $table->unsignedSmallInteger('date_year')->nullable(); // temporal filter

            $table->unsignedSmallInteger('chunk_index')->default(0);
            $table->text('content');                             // ქართული თარგმანი
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('german_cases')->cascadeOnDelete();
            $table->index(['case_id', 'chunk_index']);
            $table->index('external_id');
            $table->index('level_of_appeal');
            $table->index('decision_type');
            $table->index('date_year');
        });

        // Vector column + HNSW index
        DB::connection('pgvector')->statement(
            'ALTER TABLE german_chunks ADD COLUMN embedding vector(1024)'
        );
        DB::connection('pgvector')->statement('
            CREATE INDEX german_chunks_embedding_hnsw
            ON german_chunks
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 128)
        ');

        // Text search on Georgian content
        DB::connection('pgvector')->statement('
            CREATE INDEX german_chunks_content_trgm
            ON german_chunks USING gin(content gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement("
            CREATE INDEX german_chunks_content_fts
            ON german_chunks USING gin(to_tsvector('simple', coalesce(content, '')))
        ");
    }

    public function down(): void
    {
        Schema::connection('pgvector')->dropIfExists('german_chunks');
        Schema::connection('pgvector')->dropIfExists('german_cases');
        Schema::connection('pgvector')->dropIfExists('german_cases_queue');
    }
};
