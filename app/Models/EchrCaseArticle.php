<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EchrCaseArticle extends Model
{
    protected $connection = 'pgvector';
    protected $table      = 'echr_case_articles';

    protected $fillable = [
        'echr_case_id',
        'article_code',
        'article_label',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(EchrCase::class, 'echr_case_id');
    }
}
