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

        // ── Cases: metadata + full English text ──────────────────────────────
        Schema::connection('pgvector')->create('us_cases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('external_id', 100)->unique();          // CAP case ID
            $table->string('name_abbreviation', 500)->nullable();  // "Roe v. Wade"
            $table->text('name')->nullable();                      // full party names
            $table->string('citation', 200)->nullable();           // "410 U.S. 113"
            $table->date('decision_date')->nullable();
            $table->unsignedSmallInteger('decision_year')->nullable();
            $table->string('court_name', 300)->nullable();         // "Supreme Court of the United States"
            $table->string('jurisdiction', 100)->nullable();       // "U.S."
            $table->string('volume', 50)->nullable();
            $table->string('reporter', 100)->nullable();           // "U.S."
            $table->text('content')->nullable();                   // full English text (opinions)
            $table->string('content_hash', 64)->nullable();
            $table->timestamps();

            $table->index('decision_date');
            $table->index('decision_year');
        });

        DB::connection('pgvector')->statement('
            CREATE INDEX us_cases_name_abbreviation_trgm
            ON us_cases USING gin(name_abbreviation gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX us_cases_citation_trgm
            ON us_cases USING gin(citation gin_trgm_ops)
        ');

        // ── Chunks: Georgian translation + embedding ──────────────────────────
        Schema::connection('pgvector')->create('us_chunks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('case_id');

            // Denormalized for JOIN-free retrieval
            $table->string('external_id', 100);
            $table->string('name_abbreviation', 500)->nullable();
            $table->string('citation', 200)->nullable();
            $table->string('court_name', 300)->nullable();
            $table->unsignedSmallInteger('decision_year')->nullable();

            $table->unsignedSmallInteger('chunk_index')->default(0);
            $table->text('content');                               // ქართული თარგმანი
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('us_cases')->cascadeOnDelete();
            $table->index(['case_id', 'chunk_index']);
            $table->index('external_id');
            $table->index('decision_year');
        });

        // Vector column + HNSW index
        DB::connection('pgvector')->statement(
            'ALTER TABLE us_chunks ADD COLUMN embedding vector(1024)'
        );
        DB::connection('pgvector')->statement('
            CREATE INDEX us_chunks_embedding_hnsw
            ON us_chunks
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 128)
        ');

        // Text search on Georgian content
        DB::connection('pgvector')->statement('
            CREATE INDEX us_chunks_content_trgm
            ON us_chunks USING gin(content gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement("
            CREATE INDEX us_chunks_content_fts
            ON us_chunks USING gin(to_tsvector('simple', coalesce(content, '')))
        ");
    }

    public function down(): void
    {
        Schema::connection('pgvector')->dropIfExists('us_chunks');
        Schema::connection('pgvector')->dropIfExists('us_cases');
    }
};
