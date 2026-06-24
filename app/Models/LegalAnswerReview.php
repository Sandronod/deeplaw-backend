<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalAnswerReview extends Model
{
    protected $fillable = [
        'chat_id',
        'chat_message_id',
        'reviewer_id',
        'reviewer_name',
        'overall_score',
        'legal_accuracy_score',
        'norm_coverage_score',
        'case_law_score',
        'source_routing_score',
        'clarity_score',
        'verdict',
        'used_norms_snapshot',
        'correct_norms',
        'incorrect_norms',
        'missing_norms',
        'used_cases_snapshot',
        'correct_cases',
        'irrelevant_cases',
        'missing_cases',
        'requested_sources_snapshot',
        'used_sources_snapshot',
        'source_checks',
        'improvement_actions',
        'notes',
    ];

    protected $casts = [
        'used_norms_snapshot'       => 'array',
        'correct_norms'             => 'array',
        'incorrect_norms'           => 'array',
        'missing_norms'             => 'array',
        'used_cases_snapshot'       => 'array',
        'correct_cases'             => 'array',
        'irrelevant_cases'          => 'array',
        'missing_cases'             => 'array',
        'requested_sources_snapshot' => 'array',
        'used_sources_snapshot'     => 'array',
        'source_checks'             => 'array',
        'improvement_actions'       => 'array',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
