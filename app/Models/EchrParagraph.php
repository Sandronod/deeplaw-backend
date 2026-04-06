<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EchrParagraph extends Model
{
    protected $connection = 'pgvector';
    protected $table      = 'echr_paragraphs';

    protected $fillable = [
        'echr_case_id',
        'section_type',
        'paragraph_num',
        'chunk_index',
        'content',
        'embedding',
    ];

    protected $casts = [
        'embedding'     => 'array',
        'chunk_index'   => 'integer',
        'paragraph_num' => 'integer',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(EchrCase::class, 'echr_case_id');
    }
}
