<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EchrCase extends Model
{
    protected $connection = 'pgvector';
    protected $table      = 'echr_cases';

    protected $fillable = [
        'hudoc_itemid',
        'application_number',
        'title',
        'title_normalized',
        'judgment_date',
        'decision_date',
        'language',
        'importance',
        'originating_body',
        'document_type',
        'chamber',
        'respondent_state',
        'source_url',
        'summary',
        'full_text',
        'excerpt',
        'metadata',
        'status',
        'last_synced_at',
    ];

    protected $casts = [
        'judgment_date'  => 'date',
        'decision_date'  => 'date',
        'last_synced_at' => 'datetime',
        'metadata'       => 'array',
        'importance'     => 'integer',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(EchrCaseArticle::class, 'echr_case_id');
    }

    public function paragraphs(): HasMany
    {
        return $this->hasMany(EchrParagraph::class, 'echr_case_id')
                    ->orderBy('chunk_index');
    }

    /** Effective date — judgment_date preferred over decision_date. */
    public function effectiveDate(): ?\Illuminate\Support\Carbon
    {
        return $this->judgment_date ?? $this->decision_date;
    }

    public function articleCodes(): array
    {
        return $this->articles->pluck('article_code')->unique()->values()->toArray();
    }
}
