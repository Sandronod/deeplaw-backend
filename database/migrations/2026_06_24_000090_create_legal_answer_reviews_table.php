<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_answer_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reviewer_name')->nullable();

            $table->unsignedTinyInteger('overall_score');
            $table->unsignedTinyInteger('legal_accuracy_score')->nullable();
            $table->unsignedTinyInteger('norm_coverage_score')->nullable();
            $table->unsignedTinyInteger('case_law_score')->nullable();
            $table->unsignedTinyInteger('source_routing_score')->nullable();
            $table->unsignedTinyInteger('clarity_score')->nullable();
            $table->string('verdict', 32);

            $table->jsonb('used_norms_snapshot')->nullable();
            $table->jsonb('correct_norms')->nullable();
            $table->jsonb('incorrect_norms')->nullable();
            $table->jsonb('missing_norms')->nullable();

            $table->jsonb('used_cases_snapshot')->nullable();
            $table->jsonb('correct_cases')->nullable();
            $table->jsonb('irrelevant_cases')->nullable();
            $table->jsonb('missing_cases')->nullable();

            $table->jsonb('requested_sources_snapshot')->nullable();
            $table->jsonb('used_sources_snapshot')->nullable();
            $table->jsonb('source_checks')->nullable();
            $table->jsonb('improvement_actions')->nullable();

            $table->longText('notes')->nullable();
            $table->timestamps();

            $table->unique(['chat_message_id', 'reviewer_id']);
            $table->index(['chat_id', 'created_at']);
            $table->index(['verdict', 'overall_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_answer_reviews');
    }
};
