<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LawVersion extends Model
{
    protected $connection = 'pgvector';

    protected $fillable = [
        'law_id',
        'version_date',
        'version_label',
        'is_current',
        'change_note',
        'fetched_at',
    ];

    protected $casts = [
        'version_date' => 'date',
        'is_current'   => 'boolean',
        'fetched_at'   => 'datetime',
    ];

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(LawArticle::class);
    }
}
