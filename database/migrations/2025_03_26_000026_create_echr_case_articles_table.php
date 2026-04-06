<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        Schema::create('echr_case_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('echr_case_id')
                  ->constrained('echr_cases')
                  ->cascadeOnDelete();
            $table->string('article_code', 20);          // "6", "8", "10", "P1-1", "P1-3"
            $table->string('article_label', 150)->nullable(); // "Article 6 - Right to a fair trial"
            $table->timestamps();

            $table->index(['echr_case_id', 'article_code'], 'echr_case_articles_case_article_idx');
            $table->index('article_code', 'echr_case_articles_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('echr_case_articles');
    }
};
