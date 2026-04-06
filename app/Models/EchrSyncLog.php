<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EchrSyncLog extends Model
{
    protected $connection = 'pgvector';
    protected $table      = 'echr_sync_logs';

    public $timestamps = false;

    protected $fillable = [
        'source',
        'query_type',
        'query_value',
        'cases_fetched',
        'cases_new',
        'status',
        'error_message',
        'details',
        'synced_at',
    ];

    protected $casts = [
        'details'   => 'array',
        'synced_at' => 'datetime',
    ];
}
