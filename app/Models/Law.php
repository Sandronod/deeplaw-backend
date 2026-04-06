<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Law extends Model
{
    protected $connection = 'pgvector';

    protected $fillable = [
        'matsne_id',
        'title',
        'document_num',
        'category',
        'status',
        'adopted_at',
        'source_url',
        'current_version_id',
    ];

    protected $casts = [
        'adopted_at' => 'date',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(LawArticle::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LawVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(LawVersion::class, 'current_version_id');
    }
}
