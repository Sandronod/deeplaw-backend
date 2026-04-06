<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LawArticle extends Model
{
    protected $connection = 'pgvector';

    public $timestamps = false;

    protected $fillable = [
        'law_id',
        'law_version_id',
        'article_num',
        'article_title',
        'content',
        'chunk_index',
    ];

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LawVersion::class, 'law_version_id');
    }
}
