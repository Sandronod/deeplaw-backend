<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        Schema::create('echr_cases', function (Blueprint $table) {
            $table->id();
            $table->string('hudoc_itemid', 50)->unique();          // e.g. "001-204134"
            $table->string('application_number', 60)->nullable();  // e.g. "16812/11"
            $table->string('title')->nullable();
            $table->string('title_normalized')->nullable();        // lower() for matching
            $table->date('judgment_date')->nullable();
            $table->date('decision_date')->nullable();
            $table->string('language', 10)->default('ENG');
            $table->unsignedTinyInteger('importance')->nullable(); // 1=key, 2=high, 3=medium, 4=low
            $table->string('originating_body', 100)->nullable();  // "Grand Chamber"
            $table->string('document_type', 60)->nullable();      // "GRANDCHAMBER", "CHAMBER", "DECISION"
            $table->string('chamber', 100)->nullable();
            $table->string('respondent_state', 20)->nullable();   // "GEO", "RUS", etc.
            $table->string('source_url', 500)->nullable();
            $table->text('summary')->nullable();                   // conclusion field from HUDOC
            $table->longText('full_text')->nullable();
            $table->text('excerpt')->nullable();                   // first ~1000 chars for quick display
            $table->jsonb('metadata')->nullable();                 // raw HUDOC columns
            $table->string('status', 20)->default('active');      // active | superseded
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('echr_cases');
    }
};
