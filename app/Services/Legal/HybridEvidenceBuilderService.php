<?php

namespace App\Services\Legal;

use App\DTOs\EchrResult;
use App\DTOs\LawResult;
use App\DTOs\SourcePlan;

/**
 * Assembles a unified evidence structure from domestic cases, law articles, and ECHR cases.
 * Returns a structured array consumed by AnswerServiceInterface implementations.
 */
class HybridEvidenceBuilderService
{
    /**
     * @param  array       $domesticDecisions  Enriched decision arrays from LegalCaseRetrieverService
     * @param  LawResult[] $lawResults          From LawRetrieverService
     * @param  EchrResult[] $echrResults         From EchrRetrieverService
     * @param  SourcePlan  $plan
     * @return array{law: array, domestic_cases: array, echr_cases: array, summary: string}
     */
    public function build(
        array      $domesticDecisions,
        array      $lawResults,
        array      $echrResults,
        SourcePlan $plan,
    ): array {
        $evidence = [
            'law'            => [],
            'domestic_cases' => [],
            'echr_cases'     => [],
            'summary'        => '',
        ];

        if ($plan->useLaw && !empty($lawResults)) {
            $evidence['law'] = array_map(fn(LawResult $r) => [
                'law_id'        => $r->lawId,
                'title'         => $r->title,
                'article_num'   => $r->articleNum,
                'article_title' => $r->articleTitle,
                'excerpt'       => $r->excerpt,
                'similarity'    => $r->similarity,
                'url'           => $r->sourceUrl,
            ], $lawResults);
        }

        if ($plan->useDomestic && !empty($domesticDecisions)) {
            $evidence['domestic_cases'] = $domesticDecisions;
        }

        if ($plan->useEchr && !empty($echrResults)) {
            $evidence['echr_cases'] = array_map(fn(EchrResult $r) => [
                'case_id'            => $r->caseId,
                'hudoc_itemid'       => $r->hudocItemId,
                'application_number' => $r->applicationNumber,
                'title'              => $r->title,
                'judgment_date'      => $r->judgmentDate,
                'document_type'      => $r->documentType,
                'importance'         => $r->importance,
                'echr_articles'      => $r->echrArticles,
                'excerpt'            => $r->excerpt,
                'similarity'         => $r->similarity,
                'url'                => $r->sourceUrl,
            ], $echrResults);
        }

        $evidence['summary'] = $this->buildSummary($evidence);

        return $evidence;
    }

    private function buildSummary(array $evidence): string
    {
        $parts = [];
        if (!empty($evidence['law'])) {
            $parts[] = count($evidence['law']) . ' კანონის მუხლი';
        }
        if (!empty($evidence['domestic_cases'])) {
            $parts[] = count($evidence['domestic_cases']) . ' ქართული გადაწყვეტილება';
        }
        if (!empty($evidence['echr_cases'])) {
            $parts[] = count($evidence['echr_cases']) . ' ECHR საქმე';
        }
        return empty($parts) ? 'evidence not found' : implode(' + ', $parts);
    }
}
