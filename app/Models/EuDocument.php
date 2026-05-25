<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EuDocument extends Model
{
    protected $connection = 'pgvector';
    protected $table      = 'eu_documents';

    protected $fillable = [
        'cellar_id', 'doc_type', 'source', 'court',
        'case_num', 'title', 'doc_date', 'language',
        'content', 'embedding', 'meta', 'content_hash',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
