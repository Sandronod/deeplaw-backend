<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('law_id')->constrained('laws')->cascadeOnDelete();
            $table->string('article_num', 32)->nullable();  // e.g. "მუხლი 5"
            $table->text('article_title')->nullable();
            $table->text('content');
            $table->unsignedSmallInteger('chunk_index')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        // pgvector embedding column (3072 dims — text-embedding-3-large)
        DB::statement('ALTER TABLE law_articles ADD COLUMN embedding vector(3072)');

        // Note: IVFFlat supports max 2000 dims — not usable for 3072-dim embeddings.
        // Exact cosine search is used instead (fast enough for small law datasets).

        // Trigram index for ILIKE search on content
        DB::statement('
            CREATE INDEX law_articles_content_trgm
            ON law_articles
            USING gin(content gin_trgm_ops)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('law_articles');
    }
};
