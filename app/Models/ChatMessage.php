<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function getCitationsAttribute(): array
    {
        return $this->meta['citations'] ?? [];
    }
}
