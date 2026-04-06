<?php

namespace App\Services\Echr;

use App\DTOs\EchrResult;

/**
 * Builds frontend-ready ECHR citation objects from EchrResult DTOs.
 * Output matches the EchrCitation interface in message.model.ts.
 */
class EchrCitationBuilder
{
    /**
     * @param  EchrResult[] $results
     * @return array[]
     */
    public function build(array $results): array
    {
        return array_map(fn(EchrResult $r) => [
            'type'               => 'echr',
            'case_id'            => $r->caseId,
            'hudoc_itemid'       => $r->hudocItemId,
            'application_number' => $r->applicationNumber,
            'title'              => $r->title,
            'judgment_date'      => $r->judgmentDate,
            'document_type'      => $r->documentType,
            'importance'         => $r->importance,
            'echr_articles'      => $r->echrArticles,
            'excerpt'            => $r->excerpt,
            'relevance_score'    => $r->similarity,
            'source_url'         => $r->sourceUrl,
        ], $results);
    }
}
