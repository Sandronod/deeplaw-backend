<?php

namespace App\Services\Legal;

use App\DTOs\IssueList;
use App\DTOs\TriageResult;
use App\Services\AI\IntentClassifierService;
use App\Services\AI\IssueSpotterService;
use App\Services\AI\QueryExtractorService;
use Illuminate\Support\Facades\Log;

/**
 * "თერაპევტი" — ყველა სხვა სერვისამდე გაეშვება.
 *
 * შეჰყავს: კაზუსი (user question)
 * გამოაქვს: TriageResult — სრული სტრატეგია retrieval-ისა და routing-ისთვის
 *
 * გამოყენება:
 *   1. IntentClassifier  → intent + mode   (API call არ არის)
 *   2. QueryExtractor    → search terms    (GPT-4.1-mini)
 *   3. IssueSpotter      → legal issues    (GPT-4.1-mini, conditional)
 *   4. LegalDomainClassifier → domains    (API call არ არის)
 *   5. Source routing    → needsNorms, needsCases …
 */
class LegalTriageService
{
    public function __construct(
        private readonly IntentClassifierService $intentClassifier,
        private readonly IssueSpotterService     $issueSpotter,
        private readonly QueryExtractorService   $queryExtractor,
        private readonly LegalDomainClassifier   $domainClassifier,
    ) {}

    public function triage(string $question, array $activeSources = []): TriageResult
    {
        // ── 1. Intent — chat vs. search (no API call) ─────────────────────────
        $intent = $this->intentClassifier->classify($question);

        if ($intent === 'chat') {
            Log::debug('Triage: chat intent, skipping all retrieval');
            return TriageResult::chat();
        }

        // ── 2. Mode (no API call) ─────────────────────────────────────────────
        $mode = $this->intentClassifier->classifyMode($question);

        // ── 3. Search terms + domain (GPT-4.1-mini, single call) ────────────
        ['query' => $searchQuery, 'domain' => $llmDomain] = $this->queryExtractor->extractWithDomain($question);

        // ── 4. Issue spotting — only for complex/advise cases ─────────────────
        $issueList  = IssueList::empty();
        $shouldSpot = in_array($mode, ['advise', 'advocate'])
            || mb_strlen($question) > 150;

        if ($shouldSpot) {
            $issueList = $this->issueSpotter->spot($question);
            Log::debug('Triage: issue spotter', [
                'count'   => $issueList->issueCount,
                'complex' => $issueList->isComplex,
            ]);
        }

        // ── 5. Domains — LLM domain takes priority over keyword classifier ────
        if ($issueList->issueCount > 0) {
            $domains = $issueList->domains();
        } elseif ($llmDomain !== null) {
            $domains = [$llmDomain];
        } else {
            $domains = $this->domainClassifier->classifyMultiple([$question]);
        }

        Log::debug('Triage: domain resolved', [
            'llm_domain'      => $llmDomain,
            'issue_domains'   => $issueList->issueCount > 0 ? $issueList->domains() : [],
            'keyword_domains' => $llmDomain === null ? $this->domainClassifier->classifyMultiple([$question]) : [],
            'final'           => $domains,
        ]);

        // ── 6. Case type (DB filter) ──────────────────────────────────────────
        $primaryDomain = $domains[0] ?? null;
        $caseType      = $this->domainToCaseType($primaryDomain);

        // ── 7. Temporal year from question text ───────────────────────────────
        $temporalYear = null;
        if (preg_match('/\b(19|20)\d{2}\b/', $question, $m)) {
            $y = (int) $m[0];
            if ($y >= 1990 && $y <= (int) date('Y')) {
                $temporalYear = $y;
            }
        }

        // ── 8. Source routing ─────────────────────────────────────────────────
        // activeSources — user-selected sources from the UI
        // Triage can further restrict (e.g. pure criminal → no EU)
        $wantsCourt      = empty($activeSources) || in_array('court',       $activeSources);
        $wantsMatsne     = empty($activeSources) || in_array('matsne',      $activeSources);
        $wantsConstCourt = empty($activeSources) || in_array('const_court', $activeSources);
        $wantsEu         = empty($activeSources) || in_array('eu',          $activeSources);
        $wantsGerman     = empty($activeSources) || in_array('german',      $activeSources);

        // სისხლის საქმეში EU/ConstCourt ნაკლებად გამოდგება
        $isCriminal = $caseType === 'criminal';

        $needsNorms      = $wantsMatsne;
        $needsCases      = $wantsCourt;
        $needsConstCourt = $wantsConstCourt && !$isCriminal;
        $needsEu         = $wantsEu        && !$isCriminal;
        $needsGerman     = $wantsGerman    && !$isCriminal;

        $result = new TriageResult(
            intent:          $intent,
            mode:            $mode,
            caseType:        $caseType ?? 'any',
            domains:         $domains,
            issueList:       $issueList,
            searchQuery:     $searchQuery,
            needsNorms:      $needsNorms,
            needsCases:      $needsCases,
            needsConstCourt: $needsConstCourt,
            needsEu:         $needsEu,
            needsGerman:     $needsGerman,
            temporalYear:    $temporalYear,
            isComplex:       $issueList->isComplex || count($domains) > 1,
        );

        Log::debug('Triage: complete', $result->toDebugArray());

        return $result;
    }

    // ── Domain → DB case_type mapping ────────────────────────────────────────

    private function domainToCaseType(?string $domain): ?string
    {
        return match ($domain) {
            'criminal'                                    => 'criminal',
            'admin', 'tax'                                => 'administrative',
            'civil', 'family', 'property',
            'corporate', 'labor', 'procedure'             => 'civil',
            default                                       => null,
        };
    }
}
