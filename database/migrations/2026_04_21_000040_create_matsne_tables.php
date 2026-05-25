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

        // ── Queue: scraping progress tracker ─────────────────────────────────
        Schema::connection('pgvector')->create('matsne_doc_queue', function (Blueprint $table) {
            $table->unsignedBigInteger('matsne_id')->primary();
            $table->string('title', 1000)->nullable();
            $table->string('doc_type', 100)->nullable();
            $table->string('status', 20)->default('pending'); // pending|processing|done|failed
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();

            $table->index('status');
        });

        // ── Documents: one row per Matsne document ────────────────────────────
        Schema::connection('pgvector')->create('matsne_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('matsne_id')->unique();

            // Basic info
            $table->string('title', 1000)->nullable();
            $table->string('doc_type', 100)->nullable();   // კანონი, დადგენილება, ბრძანება...
            $table->string('doc_number', 100)->nullable(); // №1234
            $table->string('issuer', 300)->nullable();     // პარლამენტი, მთავრობა...

            // Dates
            $table->date('signing_date')->nullable();      // ხელმოწერის თარიღი
            $table->date('publish_date')->nullable();      // გამოქვეყნების თარიღი
            $table->date('effective_from')->nullable();    // ძალაში შესვლა
            $table->date('effective_to')->nullable();      // ძალადაკარგვა (null = მოქმედია)

            // Status
            $table->boolean('is_active')->default(true);  // მოქმედი / გაუქმებული

            $table->string('content_hash', 64)->nullable();
            $table->timestamps();

            $table->index('doc_type');
            $table->index('is_active');
            $table->index('signing_date');
            $table->index('effective_from');
            $table->index('effective_to');
        });

        DB::connection('pgvector')->statement('
            CREATE INDEX matsne_documents_title_trgm
            ON matsne_documents USING gin(title gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX matsne_documents_issuer_trgm
            ON matsne_documents USING gin(issuer gin_trgm_ops)
        ');

        // ── Chunks: embedded text — denormalized for JOIN-free retrieval ──────
        Schema::connection('pgvector')->create('matsne_chunks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('document_id');

            // Denormalized — no JOIN needed during vector search
            $table->unsignedBigInteger('matsne_id');
            $table->string('title', 1000)->nullable();
            $table->string('doc_type', 100)->nullable();
            $table->string('issuer', 300)->nullable();
            $table->boolean('is_active')->default(true);

            // Integer years for fast temporal filtering: "2000 წელს მოქმედი?"
            $table->unsignedSmallInteger('effective_from_year')->nullable();
            $table->unsignedSmallInteger('effective_to_year')->nullable();

            $table->unsignedSmallInteger('chunk_index')->default(0);
            $table->text('content');
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('matsne_documents')->cascadeOnDelete();
            $table->index(['document_id', 'chunk_index']);
            $table->index('matsne_id');
            $table->index('doc_type');
            $table->index('is_active');
            $table->index('effective_from_year');
            $table->index('effective_to_year');
        });

        // Vector column (pgvector DDL)
        DB::connection('pgvector')->statement(
            'ALTER TABLE matsne_chunks ADD COLUMN embedding vector(1024)'
        );

        // HNSW — correct for progressive inserts (IVFFlat is wrong on empty table)
        DB::connection('pgvector')->statement('
            CREATE INDEX matsne_chunks_embedding_hnsw
            ON matsne_chunks
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 128)
        ');

        // Text search indexes
        DB::connection('pgvector')->statement('
            CREATE INDEX matsne_chunks_content_trgm
            ON matsne_chunks USING gin(content gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement('
            CREATE INDEX matsne_chunks_title_trgm
            ON matsne_chunks USING gin(title gin_trgm_ops)
        ');
        DB::connection('pgvector')->statement("
            CREATE INDEX matsne_chunks_content_fts
            ON matsne_chunks USING gin(to_tsvector('simple', coalesce(content, '')))
        ");
    }

    public function down(): void
    {
        Schema::connection('pgvector')->dropIfExists('matsne_chunks');
        Schema::connection('pgvector')->dropIfExists('matsne_documents');
        Schema::connection('pgvector')->dropIfExists('matsne_doc_queue');
    }
};
