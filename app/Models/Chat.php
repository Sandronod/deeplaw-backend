<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }

    public function latestMessages(int $limit = 6): HasMany
    {
        return $this->hasMany(ChatMessage::class)
            ->orderByDesc('created_at')
            ->limit($limit);
    }
}
