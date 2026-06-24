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
        $wantsMatsnePre = empty($activeSources) || in_array('matsne', $activeSources, true);
        $simpleRuleApplication = $wantsMatsnePre && $this->isSimpleRuleApplicationQuestion($question, $mode);

        // ── 3. Search terms + domain (GPT-4.1-mini, single call) ────────────
        $queryExtraction = $this->queryExtractor->extractWithDomain($question);
        $searchQuery = $queryExtraction['query'];
        $llmDomain = $queryExtraction['domain'] ?? null;
        $queryNormalization = $queryExtraction['normalization'] ?? [];

        // ── 4. Issue spotting — only for complex/advise cases ─────────────────
        $issueList  = IssueList::empty();
        $shouldSpot = !$simpleRuleApplication
            && (in_array($mode, ['advise', 'advocate']) || mb_strlen($question) > 150);

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
        $caseType = $this->resolveCaseType($domains);
        $caseType = $this->relaxCivilFilterForPublicLawSignals($question, $caseType);

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
        $wantsEchr       = empty($activeSources) || in_array('echr',        $activeSources) || $this->hasEchrSignals($question);
        $wantsConstCourt = empty($activeSources) || in_array('const_court', $activeSources) || $this->hasConstCourtSignals($question);
        $wantsEu         = empty($activeSources) || in_array('eu',          $activeSources) || $this->hasEuSignals($question);
        $wantsGerman     = empty($activeSources) || in_array('german',      $activeSources) || $this->hasGermanSignals($question);
        $simpleDomesticNormLookup = $this->isSimpleDomesticNormLookup($question, $mode, $wantsMatsne);
        $wantsNonDomesticAuthority = $wantsEchr || $wantsConstCourt || $wantsEu || $wantsGerman;

        // სისხლის საქმეში EU/ConstCourt ნაკლებად გამოდგება
        $isCriminal = $caseType === 'criminal';

        $needsNorms      = $wantsMatsne;
        $normOnlyAnswer  = !$wantsNonDomesticAuthority && ($simpleDomesticNormLookup || $simpleRuleApplication);
        $needsCases      = $wantsCourt      && !$normOnlyAnswer;
        $needsConstCourt = $wantsConstCourt && !$isCriminal && !$normOnlyAnswer;
        $needsEu         = $wantsEu         && !$isCriminal && !$normOnlyAnswer;
        $needsGerman     = $wantsGerman     && !$isCriminal && !$normOnlyAnswer;

        $complexity = $this->classifyComplexity(
            question:      $question,
            mode:          $mode,
            domains:       $domains,
            issueList:     $issueList,
            needsNorms:    $needsNorms,
            needsCases:    $needsCases,
            activeSources: $activeSources,
        );

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
            isComplex:       $issueList->isComplex || count($domains) > 1 || $complexity['level'] === 'full',
            complexityScore:  $complexity['score'],
            complexityLevel:  $complexity['level'],
            complexityReasons: $complexity['reasons'],
            queryNormalization: $queryNormalization,
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

    private function resolveCaseType(array $domains): ?string
    {
        $substantiveDomains = array_values(array_filter(
            $domains,
            fn (string $domain) => !in_array($domain, ['procedure', 'echr'], true)
        ));

        if (empty($substantiveDomains)) {
            return null;
        }

        $caseTypes = array_values(array_unique(array_filter(
            array_map(fn (string $domain) => $this->domainToCaseType($domain), $substantiveDomains)
        )));

        return count($caseTypes) === 1 ? $caseTypes[0] : null;
    }

    private function relaxCivilFilterForPublicLawSignals(string $question, ?string $caseType): ?string
    {
        if ($caseType !== 'civil') {
            return $caseType;
        }

        $lower = mb_strtolower($question);
        $publicLawSignals = [
            'ადმინისტრაც',
            'სამინისტრო',
            'სსიპ',
            'საჯარო',
            'მერია',
            'საკრებულ',
            'პოლიცი',
            'ფინანსურ',
            'შემოსავლების სამსახ',
            'კომისი',
            'სახელმწიფო ორგან',
            'ადმინისტრაციული ორგან',
        ];

        foreach ($publicLawSignals as $signal) {
            if (str_contains($lower, $signal)) {
                return null;
            }
        }

        return $caseType;
    }

    private function isSimpleRuleApplicationQuestion(string $question, string $mode): bool
    {
        if (!in_array($mode, ['explain', 'find', 'summarize'], true)) {
            return false;
        }

        $question = trim($question);
        if ($question === '' || mb_strlen($question) > 520) {
            return false;
        }

        if ($this->questionMarkCount($question) > 1) {
            return false;
        }

        if ($this->hasCourtPracticeSignals($question) || $this->hasInternationalOrComparativeSignals($question)) {
            return false;
        }

        return $this->hasDecisionPromptSignal($question)
            && $this->hasRuleBoundarySignal($question);
    }

    private function questionMarkCount(string $question): int
    {
        return substr_count($question, '?') + substr_count($question, '？');
    }

    private function hasDecisionPromptSignal(string $question): bool
    {
        $lower = mb_strtolower($question);
        $signals = [
            'შეუძლია თუ არა',
            'უნდა თუ არა',
            'უნდა შემოწმდეს',
            'ნიშნავს თუ არა',
            'არის თუ არა',
            'შეიძლება თუ არა',
            'აქვს თუ არა',
            'დასაშვებია თუ არა',
            'განსჯადია',
            'ვადაშია',
            'can ',
            'whether',
            'does ',
            'should ',
        ];

        foreach ($signals as $signal) {
            if (str_contains($lower, $signal)) {
                return true;
            }
        }

        return $this->questionMarkCount($question) === 1;
    }

    private function hasRuleBoundarySignal(string $question): bool
    {
        $lower = mb_strtolower($question);

        if (preg_match('/\d[\d\s,.]*(?:ლარ|₾|დღ|თვ|წელ|%)/u', $lower)) {
            return true;
        }

        $signals = [
            'ვად',
            'ჩაბარ',
            'სააპელაციო',
            'საკასაციო',
            'საჩივ',
            'ხანდაზმ',
            'ფას',
            'თანხ',
            'ზღვ',
            'მაგისტრატ',
            'განსჯად',
            'შეგებებულ',
            'ნაკლ',
            'შეტყობ',
            'აცნობ',
            'შეწყვეტ',
            'გათავისუფლ',
            'დასაბუთ',
            'ადმინისტრაციული აქტი',
            'ბათილ',
            'უფლება',
        ];

        foreach ($signals as $signal) {
            if (str_contains($lower, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function isSimpleDomesticNormLookup(string $question, string $mode, bool $wantsMatsne): bool
    {
        if (!$wantsMatsne || !$this->hasExactArticleReference($question)) {
            return false;
        }

        if (!in_array($mode, ['explain', 'find', 'summarize'], true)) {
            return false;
        }

        return !$this->hasCourtPracticeSignals($question)
            && !$this->hasInternationalOrComparativeSignals($question)
            && !$this->hasFactPatternSignals($question);
    }

    private function hasCourtPracticeSignals(string $question): bool
    {
        $lower = mb_strtolower($question);
        $signals = [
            'სასამართლო პრაქტიკ',
            'პრაქტიკ',
            'სასამართლო გადაწყვეტილ',
            'გადაწყვეტილებებ',
            'გადაწყვეტილება მომიძებნ',
            'პრეცედენტ',
            'უზენაეს',
            'court',
            'case law',
            'decision',
            'precedent',
        ];

        foreach ($signals as $signal) {
            if (str_contains($lower, $signal)) {
                return true;
            }
        }

        return $this->hasExactCaseNumber($question);
    }

    private function hasInternationalOrComparativeSignals(string $question): bool
    {
        $lower = mb_strtolower($question);
        $signals = [
            'echr',
            'სტრასბურგ',
            'კონვენცი',
            'ევრო',
            'გერმან',
            'შეადარ',
            'compare',
            'german',
            'european',
        ];

        foreach ($signals as $signal) {
            if (str_contains($lower, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function hasEuSignals(string $question): bool
    {
        return $this->containsAny(mb_strtolower($question), [
            'eu',
            'ევროკავშირ',
            'ევროკავშირის სამართალ',
            'ევროპული კავშირ',
            'directive',
            'regulation',
            'cjeu',
            'court of justice',
        ]);
    }

    private function hasEchrSignals(string $question): bool
    {
        return $this->containsAny(mb_strtolower($question), [
            'echr',
            'hudoc',
            'სტრასბურგ',
            'კონვენცი',
            'ადამიანის უფლებათა ევროპული სასამართლო',
            'ადამიანის უფლებათა ევროპული კონვენცია',
            'european court of human rights',
            'fair trial',
            'article 6',
            'article 8',
        ]);
    }

    private function hasGermanSignals(string $question): bool
    {
        return $this->containsAny(mb_strtolower($question), [
            'გერმან',
            'გერმანიის სასამართლო',
            'გერმანული სასამართლო',
            'გერმანული პრაქტიკ',
            'გერმანიის პრაქტიკ',
            'გერმანული გადაწყვეტილ',
            'german',
            'germany',
            'deutsch',
            'bgh',
            'bundesgerichtshof',
            'bundesverfassungsgericht',
            'bundesarbeitsgericht',
            'olg',
        ]);
    }

    private function hasConstCourtSignals(string $question): bool
    {
        return $this->containsAny(mb_strtolower($question), [
            'საკონსტიტუციო სასამართლო',
            'საკონსტიტუციო პრაქტიკ',
            'კონსტიტუციური სარჩელ',
            'კონსტიტუციურობა',
            'არაკონსტიტუციურ',
            'constitutional court',
        ]);
    }

    /**
     * @param array<int, string> $signals
     */
    private function containsAny(string $lower, array $signals): bool
    {
        foreach ($signals as $signal) {
            if (str_contains($lower, mb_strtolower($signal))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{score:int, level:string, reasons:array<int, string>}
     */
    private function classifyComplexity(
        string $question,
        string $mode,
        array $domains,
        IssueList $issueList,
        bool $needsNorms,
        bool $needsCases,
        array $activeSources = [],
    ): array {
        $score = 40;
        $reasons = [];
        $length = mb_strlen(trim($question));
        $domainCount = count(array_unique($domains));
        $sourceCount = count($activeSources);
        $hasExactArticle = $this->hasExactArticleReference($question);
        $hasExactCase = $this->hasExactCaseNumber($question);
        $simpleRuleApplication = $this->isSimpleRuleApplicationQuestion($question, $mode);

        if ($length <= 160) {
            $score -= 10;
            $reasons[] = 'short_question';
        } elseif ($length > 500) {
            $score += 25;
            $reasons[] = 'long_fact_pattern';
        } elseif ($length > 250) {
            $score += 15;
            $reasons[] = 'medium_fact_pattern';
        } elseif ($length > 160) {
            $score += 8;
            $reasons[] = 'expanded_question';
        }

        if ($hasExactArticle) {
            $score -= 18;
            $reasons[] = 'exact_article_reference';
        }

        if ($hasExactCase) {
            $score -= 18;
            $reasons[] = 'exact_case_number';
        }

        if ($simpleRuleApplication) {
            $score -= 18;
            $reasons[] = 'simple_rule_application';
        }

        if ($issueList->issueCount >= 3 || $issueList->isComplex) {
            $score += 22;
            $reasons[] = 'multiple_issues';
        } elseif ($issueList->issueCount > 0) {
            $score += 8;
            $reasons[] = 'issue_spotted';
        }

        if ($domainCount > 1) {
            $score += 12;
            $reasons[] = 'multiple_domains';
        }

        if ($needsNorms && $needsCases) {
            $score += 8;
            $reasons[] = 'norms_and_cases';
        }

        if ($sourceCount === 1) {
            $score -= 8;
            $reasons[] = 'single_source';
        } elseif ($sourceCount > 2) {
            $score += 5;
            $reasons[] = 'multiple_sources';
        }

        if (in_array($mode, ['advise', 'advocate', 'compare'], true)) {
            $score += $mode === 'compare' ? 15 : 12;
            $reasons[] = "mode_{$mode}";
        } elseif (in_array($mode, ['find', 'explain'], true)) {
            $score -= 5;
            $reasons[] = "mode_{$mode}";
        }

        if ($this->hasFactPatternSignals($question) && !$simpleRuleApplication) {
            $score += 15;
            $reasons[] = 'fact_pattern_or_strategy';
        }

        if (substr_count($question, '?') + substr_count($question, '？') > 1) {
            $score += 8;
            $reasons[] = 'multiple_questions';
        }

        $score = max(0, min(100, $score));
        $level = match (true) {
            $score <= 30 => 'fast',
            $score >= 61 => 'full',
            default      => 'normal',
        };

        return [
            'score'   => $score,
            'level'   => $level,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function hasExactArticleReference(string $question): bool
    {
        return (bool) preg_match(
            '/(?:მუხლ\p{L}*|article|art\.?)\D{0,12}\d{1,4}|\d{1,4}\D{0,12}(?:მუხლ\p{L}*|article|art\.?)/iu',
            $question,
        );
    }

    private function hasExactCaseNumber(string $question): bool
    {
        return (bool) preg_match('/[ა-ჰ]{1,4}-\d{1,5}(?:-\d{1,5})?\([^)]+\)/u', $question);
    }

    private function hasFactPatternSignals(string $question): bool
    {
        $lower = mb_strtolower($question);
        $signals = [
            'კაზუს',
            'ფაქტ',
            'შეაფას',
            'სტრატეგ',
            'შანს',
            'სარჩელ',
            'მოპასუხ',
            'მოსარჩელ',
            'როგორ გადაწყდება',
            'რა უნდა ვქნა',
            'შეიძლება თუ არა',
            'legal strategy',
            'fact pattern',
            'can i',
            'what should',
        ];

        foreach ($signals as $signal) {
            if (str_contains($lower, $signal)) {
                return true;
            }
        }

        return false;
    }
}
