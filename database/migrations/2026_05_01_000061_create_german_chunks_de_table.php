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
        Schema::connection('pgvector')->create('german_chunks_de', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('case_id');

            $table->unsignedBigInteger('external_id');
            $table->string('court_name', 300)->nullable();
            $table->string('level_of_appeal', 100)->nullable();
            $table->string('decision_type', 100)->nullable();
            $table->string('jurisdiction', 200)->nullable();
            $table->unsignedSmallInteger('date_year')->nullable();

            $table->unsignedSmallInteger('chunk_index')->default(0);
            $table->text('content');                             // original German text
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('german_cases')->cascadeOnDelete();
            $table->index(['case_id', 'chunk_index']);
            $table->index('external_id');
            $table->index('level_of_appeal');
            $table->index('decision_type');
            $table->index('date_year');
        });

        DB::connection('pgvector')->statement(
            'ALTER TABLE german_chunks_de ADD COLUMN embedding vector(1024)'
        );

        DB::connection('pgvector')->statement('
            CREATE INDEX german_chunks_de_embedding_hnsw
            ON german_chunks_de
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 128)
        ');

        DB::connection('pgvector')->statement('
            CREATE INDEX german_chunks_de_content_trgm
            ON german_chunks_de USING gin(content gin_trgm_ops)
        ');
    }

    public function down(): void
    {
        Schema::connection('pgvector')->dropIfExists('german_chunks_de');
    }
};
