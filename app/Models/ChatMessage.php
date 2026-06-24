<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatMessage extends Model
{
    protected $fillable = ['chat_id', 'role', 'content', 'meta'];

    protected $casts = [
        'meta' => 'array',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function humanReviews(): HasMany
    {
        return $this->hasMany(LegalAnswerReview::class);
    }

    public function latestHumanReview(): HasOne
    {
        return $this->hasOne(LegalAnswerReview::class)->latestOfMany();
    }

    public function getCitationsAttribute(): array
    {
        return $this->meta['citations'] ?? [];
    }
}
