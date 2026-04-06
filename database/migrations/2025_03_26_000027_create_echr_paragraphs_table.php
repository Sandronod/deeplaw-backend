<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        Schema::create('echr_paragraphs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('echr_case_id')
                  ->constrained('echr_cases')
                  ->cascadeOnDelete();
            $table->string('section_type', 50)->nullable(); // "facts", "law", "conclusion", "body"
            $table->unsignedSmallInteger('paragraph_num')->nullable();
            $table->unsignedSmallInteger('chunk_index');
            $table->text('content');
            $table->vector('embedding', 3072)->nullable();
            $table->timestamps();

            $table->index(['echr_case_id', 'chunk_index'], 'echr_paragraphs_case_chunk_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('echr_paragraphs');
    }
};
