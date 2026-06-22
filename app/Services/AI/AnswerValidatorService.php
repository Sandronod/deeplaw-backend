<?php

namespace App\Services\AI;

class AnswerValidatorService
{
    private LegalConsequenceTaxonomyService $consequenceTaxonomy;

    private const ARTICLE_PATTERNS = [
        '/მუხლ(?:ი|ის|ით|ზე|ში|იდან|ად|ებს|ები)?\s*№?\s*(\d{1,4})(?:\.\d+)?(?!\s*(?:[-–]\s*)?(?:ე|ლი)?\s*(?:ნაწილ|პუნქტ|ქვეპუნქტ))/u',
        '/(\d{1,4})(?:-?ე|ე)?\s+მუხლ(?:ი|ის|ით|ზე|ში|იდან|ად|ებს|ები)?/u',
    ];

    private const LEGAL_NUMBER_PATTERN = '/(?<![\p{L}\p{N}])((?:\d{1,3}(?:(?:\s|\x{00A0})\d{3})+)|(?:\d+(?:[.,]\d+)?))\s*(?:[-–]\s*)?(დღ(?:ე|ის|ით|იდან|ეში|ეებს|იანი|იან)?|თვ(?:ე|ის|ით|ეში|იანი|იან)?|წელ(?:ი|ს|ით|ში|იწად|იწადი|იანი|იან)?|წლ(?:ის|ით|ამდე|იანი|იან)?|ლარ(?:ი|ის|ით)?|₾|%|პროცენტ(?:ი|ის|ით)?|კალენდარულ(?:ი|ად)?|სამუშაო|საათ(?:ი|ის|ში)?|კვირ(?:ა|ის|აში)?)/u';

    private const DOMESTIC_CASE_LAW_PHRASES = [
        'უზენაესი სასამართლ',
        'საკასაციო სასამართლ',
        'საკასაციო პალატ',
    ];

    private const GENERAL_CASE_LAW_PHRASES = [
        'სასამართლო პრაქტიკ',
        'პრაქტიკის მიხედვით',
        'პრაქტიკით',
        'სასამართლომ დაადგინა',
        'სასამართლომ განმარტა',
        'გადაწყვეტილების მიხედვით',
        'გადაწყვეტილებებში',
    ];

    private const STRONG_CASE_LAW_CLAIM_PHRASES = [
        'პრაქტიკა ადასტურებს',
        'პრაქტიკა ამყარებს',
        'პრაქტიკა ცხადყოფს',
        'პრაქტიკა აღიარებს',
        'სასამართლო პრაქტიკა ადასტურებს',
        'სასამართლო პრაქტიკა ამყარებს',
        'სასამართლო პრაქტიკა ცხადყოფს',
        'სასამართლო პრაქტიკა აღიარებს',
        'სასამართლო პრაქტიკა ადასტურებს, რომ',
        'სასამართლო პრაქტიკით დასტურდება',
    ];

    private const BINDING_AUTHORITY_CLAIM_PHRASES = [
        'სავალდებულო პრეცედენტ',
        'სავალდებულო ძალა აქვს',
        'სავალდებულოა სასამართლოებისთვის',
        'სასამართლოები ვალდებულნი არიან',
        'binding precedent',
        'binding authority',
        'mandatory precedent',
    ];

    private const NON_BINDING_NEGATION_PHRASES = [
        'არ არის სავალდებულო',
        'არ წარმოადგენს სავალდებულო',
        'არ აქვს სავალდებულო',
        'არასავალდებულო',
        'არ უწოდო სავალდებულო',
    ];

    private const REMEDY_OUTCOME_KEYWORDS = [
        'invalidity' => ['ბათილ', 'არარა', 'ნამდვილი არ არის'],
        'avoidance' => ['შეცილ', 'მოტყუ', 'სადავო გახდეს'],
        'termination' => ['მოშლ', 'შეწყვეტ'],
        'cure_or_replacement' => ['გამოასწორ', 'გამოსწორ', 'შეცვალ', 'შეცვლა'],
        'price_reduction' => ['ფასის შემცირ'],
        'damages' => ['ზიან', 'ანაზღაურ'],
        'notice_or_preclusion' => ['პრეტენზ', 'აცნობ', 'ეცნობ', 'ერთმევა'],
        'limitation' => ['ხანდაზმულ', 'ვადა'],
    ];

    public function __construct(?LegalConsequenceTaxonomyService $consequenceTaxonomy = null)
    {
        $this->consequenceTaxonomy = $consequenceTaxonomy ?? new LegalConsequenceTaxonomyService();
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $echrResults
     * @param array<int, array<string, mixed>> $extractedRules
     * @return array<string, mixed>
     */
    public function validate(
        string $answerText,
        array $decisions = [],
        array $matsneResults = [],
        array $echrResults = [],
        array $extractedRules = [],
    ): array {
        $flags = [];

        $answerArticles = $this->extractArticleNumbers($answerText);
        $sourceArticles = $this->extractSourceArticleNumbers($matsneResults, $extractedRules, $decisions, $echrResults);

        foreach (array_diff($answerArticles, $sourceArticles) as $articleNum) {
            $flags[] = $this->flag(
                'unsupported_article',
                'high',
                "პასუხში ნახსენებია მუხლი {$articleNum}, მაგრამ მოძიებულ ნორმებში ეს მუხლი არ ჩანს.",
                $articleNum,
            );
        }

        $answerNumberMentions = $this->extractLegalNumberMentions($answerText);
        $answerNumbers = $this->uniqueSorted(array_column($answerNumberMentions, 'number'));
        $sourceNumbers = $this->extractSourceLegalNumbers($decisions, $matsneResults, $echrResults, $extractedRules);

        foreach ($answerNumberMentions as $mention) {
            if (!in_array($mention['number'], $sourceNumbers, true)) {
                $flags[] = $this->flag(
                    'unsupported_number',
                    'medium',
                    "პასუხში ნახსენებია რიცხვითი წესი/ვადა {$mention['text']}, მაგრამ წყაროებში იგივე რიცხვი ვერ მოიძებნა.",
                    $mention['number'],
                    $mention['text'],
                );
            }
        }

        $caseLawFlag = $this->detectUnsupportedCaseLawClaim($answerText, $decisions, $echrResults);
        if ($caseLawFlag !== null) {
            $flags[] = $caseLawFlag;
        }

        foreach ($this->detectWeakCaseAuthorityClaims($answerText, $decisions) as $weakCaseFlag) {
            $flags[] = $weakCaseFlag;
        }

        foreach ($this->detectNonBindingCaseCalledBinding($answerText, $decisions) as $authorityFlag) {
            $flags[] = $authorityFlag;
        }

        foreach ($this->detectRemedyMismatchClaims($answerText, $decisions, $matsneResults, $echrResults, $extractedRules) as $remedyFlag) {
            $flags[] = $remedyFlag;
        }

        foreach ($this->detectProceduralConsequenceClaims($answerText) as $proceduralFlag) {
            $flags[] = $proceduralFlag;
        }

        foreach ($this->detectGenericSourcePlaceholders($answerText) as $genericSourceFlag) {
            $flags[] = $genericSourceFlag;
        }

        foreach ($this->detectPrivacyLawOmission($answerText, $matsneResults) as $privacyFlag) {
            $flags[] = $privacyFlag;
        }

        foreach ($this->detectSpecialSourceOmissions($answerText, $answerArticles, $matsneResults) as $sourceOmissionFlag) {
            $flags[] = $sourceOmissionFlag;
        }

        foreach ($this->detectCivilCode55Misuse($answerText) as $civilCode55Flag) {
            $flags[] = $civilCode55Flag;
        }

        $score = $this->score($flags);
        $verdict = $this->verdict($flags, $score);

        return [
            'verdict' => $verdict,
            'score' => $score,
            'flags' => $flags,
            'summary' => [
                'flags_count' => count($flags),
                'high_flags' => count(array_filter($flags, fn (array $f) => $f['severity'] === 'high')),
                'medium_flags' => count(array_filter($flags, fn (array $f) => $f['severity'] === 'medium')),
                'low_flags' => count(array_filter($flags, fn (array $f) => $f['severity'] === 'low')),
                'unsupported_articles_count' => count(array_filter($flags, fn (array $f) => $f['type'] === 'unsupported_article')),
                'unsupported_numbers_count' => count(array_filter($flags, fn (array $f) => $f['type'] === 'unsupported_number')),
                'unsupported_case_law_claim' => (bool) array_filter($flags, fn (array $f) => $f['type'] === 'unsupported_case_law_claim'),
                'overstated_weak_case_law_claims' => count(array_filter($flags, fn (array $f) => $f['type'] === 'overstated_weak_case_law_claim')),
                'weak_case_authority_claims' => count(array_filter($flags, fn (array $f) => $f['type'] === 'weak_case_authority_claim')),
                'non_binding_authority_claims' => count(array_filter($flags, fn (array $f) => $f['type'] === 'non_binding_case_called_binding')),
                'remedy_mismatch_claims' => count(array_filter($flags, fn (array $f) => in_array($f['type'], ['unsupported_legal_remedy', 'defect_nullity_conflation', 'defect_notice_as_challenge_period'], true))),
                'procedural_mismatch_claims' => count(array_filter($flags, fn (array $f) => in_array($f['type'], ['wrong_threshold_boundary', 'contradictory_boundary_application'], true))),
                'generic_source_placeholders' => count(array_filter($flags, fn (array $f) => $f['type'] === 'generic_source_placeholder')),
                'privacy_law_omissions' => count(array_filter($flags, fn (array $f) => in_array($f['type'], ['privacy_law_omission', 'privacy_law_source_denied'], true))),
                'special_source_omissions' => count(array_filter($flags, fn (array $f) => str_ends_with($f['type'], '_source_omission'))),
                'civil_code_55_misuse' => count(array_filter($flags, fn (array $f) => $f['type'] === 'civil_code_55_misuse')),
            ],
            'checked' => [
                'answer_articles' => $answerArticles,
                'source_articles' => $sourceArticles,
                'answer_legal_numbers' => $answerNumbers,
                'source_legal_numbers' => $sourceNumbers,
                'answer_remedies' => $this->detectOutcomeCategories($answerText),
                'source_remedies' => $this->detectSourceOutcomeCategories($decisions, $matsneResults, $echrResults, $extractedRules),
                'procedural_boundary_findings' => $this->consequenceTaxonomy->boundaryFindings($answerText),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractArticleNumbers(string $text): array
    {
        $articles = [];

        foreach (self::ARTICLE_PATTERNS as $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[1] ?? [] as $match) {
                $articles[] = (string) (int) $match;
            }
        }

        return $this->uniqueSorted($articles);
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $extractedRules
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $echrResults
     * @return array<int, string>
     */
    private function extractSourceArticleNumbers(
        array $matsneResults,
        array $extractedRules,
        array $decisions,
        array $echrResults,
    ): array
    {
        $articles = [];

        foreach ($matsneResults as $result) {
            foreach (['_article_num', 'article_num'] as $key) {
                if (isset($result[$key]) && preg_match('/\d{1,4}/', (string) $result[$key], $match)) {
                    $articles[] = (string) (int) $match[0];
                }
            }

            $articles = array_merge(
                $articles,
                $this->extractArticleNumbers(($result['title'] ?? '') . "\n" . ($result['excerpt'] ?? '')),
            );
        }

        foreach ($extractedRules as $rule) {
            if (isset($rule['article_num']) && preg_match('/\d{1,4}/', (string) $rule['article_num'], $match)) {
                $articles[] = (string) (int) $match[0];
            }
        }

        $articles = array_merge(
            $articles,
            $this->extractArticleNumbers($this->joinFields($decisions, ['excerpt', 'full_text', 'content'])),
            $this->extractArticleNumbers($this->joinFields($echrResults, ['title', 'excerpt', 'summary', 'content'])),
        );

        return $this->uniqueSorted($articles);
    }

    /**
     * @return array<int, array{number: string, text: string}>
     */
    private function extractLegalNumberMentions(string $text): array
    {
        preg_match_all(self::LEGAL_NUMBER_PATTERN, $text, $matches, PREG_SET_ORDER);

        $mentions = [];
        foreach ($matches as $match) {
            $mentions[] = [
                'number' => $this->normalizeNumber($match[1]),
                'text' => trim($match[0]),
            ];
        }

        return $mentions;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $echrResults
     * @param array<int, array<string, mixed>> $extractedRules
     * @return array<int, string>
     */
    private function extractSourceLegalNumbers(
        array $decisions,
        array $matsneResults,
        array $echrResults,
        array $extractedRules,
    ): array {
        $sourceText = implode("\n", array_filter([
            $this->joinFields($matsneResults, ['title', 'excerpt', 'content', 'text']),
            $this->joinFields($decisions, ['excerpt', 'full_text', 'content']),
            $this->joinFields($echrResults, ['title', 'excerpt', 'summary', 'content']),
            $this->joinNestedStrings($extractedRules),
        ]));

        return $this->uniqueSorted(array_column($this->extractLegalNumberMentions($sourceText), 'number'));
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $echrResults
     */
    private function detectUnsupportedCaseLawClaim(string $answerText, array $decisions, array $echrResults): ?array
    {
        $lower = mb_strtolower($answerText);

        if ($this->containsAny($lower, self::STRONG_CASE_LAW_CLAIM_PHRASES)
            && !$this->hasPrimaryCourtAuthority($decisions)
        ) {
            return $this->flag(
                'overstated_weak_case_law_claim',
                'high',
                'პასუხი წერს, რომ სასამართლო პრაქტიკა ადასტურებს დასკვნას, მაგრამ მოძიებულ გადაწყვეტილებებში PRIMARY AUTHORITY არ არის. weak/supporting საქმეები მხოლოდ ანალოგიად უნდა იყოს გამოყენებული.',
            );
        }

        if ($this->hasNegativeCaseLawStatement($lower)) {
            return null;
        }

        if ($this->containsAny($lower, self::DOMESTIC_CASE_LAW_PHRASES) && empty($decisions)) {
            return $this->flag(
                'unsupported_case_law_claim',
                'high',
                'პასუხში არის საქართველოს სასამართლო პრაქტიკაზე მითითება, მაგრამ მოძიებულ წყაროებში სასამართლო გადაწყვეტილება არ არის.',
            );
        }

        if ($this->containsAny($lower, self::GENERAL_CASE_LAW_PHRASES) && empty($decisions) && empty($echrResults)) {
            return $this->flag(
                'unsupported_case_law_claim',
                'high',
                'პასუხში არის სასამართლო პრაქტიკაზე მითითება, მაგრამ მოძიებულ წყაროებში საქმე/გადაწყვეტილება არ არის.',
            );
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @return array<int, array<string, mixed>>
     */
    private function detectWeakCaseAuthorityClaims(string $answerText, array $decisions): array
    {
        if (empty($decisions)) {
            return [];
        }

        $answerNormalized = $this->normalizeCaseText($answerText);
        $flags = [];

        foreach ($decisions as $decision) {
            $caseNum = (string) ($decision['case_num'] ?? '');
            if ($caseNum === '' || !str_contains($answerNormalized, $this->normalizeCaseText($caseNum))) {
                continue;
            }

            if (!$this->isWeakCaseSource($decision)) {
                continue;
            }

            $window = $this->caseMentionWindow($answerText, $caseNum);
            if ($this->containsAny(mb_strtolower($window), [
                'ანალოგ',
                'დამხმარ',
                'სუსტ',
                'შეზღუდულ',
                'არაპირდაპირ',
                'მსგავს',
                'weak',
                'supporting',
            ])) {
                continue;
            }

            $flags[] = $this->flag(
                'weak_case_authority_claim',
                'medium',
                "პასუხში {$caseNum} გამოყენებულია როგორც სასამართლო პრაქტიკა, მაგრამ retrieval-მა ის მხოლოდ weak/supporting წყაროდ მონიშნა.",
                $caseNum,
                mb_substr($window, 0, 180),
            );
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function isWeakCaseSource(array $decision): bool
    {
        if (($decision['answer_role'] ?? null) !== 'primary') {
            return true;
        }

        if (in_array('weak_context_match', $decision['quality_flags'] ?? [], true)) {
            return true;
        }

        $confidence = $decision['semantic_relevance']['confidence'] ?? null;
        $score = (float) ($decision['semantic_relevance_score'] ?? 100.0);

        return $confidence === 'low' || $score < 45.0;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @return array<int, array<string, mixed>>
     */
    private function detectNonBindingCaseCalledBinding(string $answerText, array $decisions): array
    {
        if (empty($decisions)) {
            return [];
        }

        $answerNormalized = $this->normalizeCaseText($answerText);
        $flags = [];

        foreach ($decisions as $decision) {
            if (!empty($decision['authority_binding'])) {
                continue;
            }

            $caseNum = (string) ($decision['case_num'] ?? '');
            if ($caseNum === '' || !str_contains($answerNormalized, $this->normalizeCaseText($caseNum))) {
                continue;
            }

            $window = $this->caseMentionWindow($answerText, $caseNum);
            $lowerWindow = mb_strtolower($window);

            if (!$this->containsAny($lowerWindow, self::BINDING_AUTHORITY_CLAIM_PHRASES)
                || $this->containsAny($lowerWindow, self::NON_BINDING_NEGATION_PHRASES)
            ) {
                continue;
            }

            $status = (string) ($decision['authority_status'] ?? 'persuasive_or_supporting');
            $flags[] = $this->flag(
                'non_binding_case_called_binding',
                'high',
                "პასუხში {$caseNum} წარმოდგენილია როგორც სავალდებულო პრეცედენტი, მაგრამ წყაროს AUTHORITY_STATUS არის {$status}.",
                $caseNum,
                mb_substr($window, 0, 180),
            );
        }

        return $flags;
    }

    private function caseMentionWindow(string $answerText, string $caseNum): string
    {
        $pos = mb_stripos($answerText, $caseNum);
        if ($pos === false) {
            return $answerText;
        }

        $start = max(0, $pos - 120);

        return mb_substr($answerText, $start, mb_strlen($caseNum) + 240);
    }

    private function normalizeCaseText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', '', $text) ?? $text));
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $echrResults
     * @param array<int, array<string, mixed>> $extractedRules
     * @return array<int, array<string, mixed>>
     */
    private function detectRemedyMismatchClaims(
        string $answerText,
        array $decisions,
        array $matsneResults,
        array $echrResults,
        array $extractedRules,
    ): array {
        $flags = [];
        $answerOutcomes = $this->detectOutcomeCategories($answerText);
        $sourceOutcomes = $this->detectSourceOutcomeCategories($decisions, $matsneResults, $echrResults, $extractedRules);

        if (!empty($sourceOutcomes)) {
            foreach (array_diff($answerOutcomes, $sourceOutcomes) as $outcome) {
                if ($this->isNegatedOutcome($answerText, $outcome)) {
                    continue;
                }

                $flags[] = $this->flag(
                    'unsupported_legal_remedy',
                    'medium',
                    "პასუხში გამოყენებულია სამართლებრივი შედეგი '{$outcome}', მაგრამ მოძიებულ წყაროებში ეს შედეგი არ ჩანს.",
                    $outcome,
                );
            }
        }

        $defectNullityWindow = $this->defectNullityWindow($answerText);
        if ($defectNullityWindow !== null
            && $this->sourceHasDefectRemedies($decisions, $matsneResults, $extractedRules)
            && !$this->hasNegation($defectNullityWindow)
        ) {
            $flags[] = $this->flag(
                'defect_nullity_conflation',
                'high',
                'პასუხი ნაკლის სამართლებრივ შედეგს პირდაპირ ბათილობად აყალიბებს; ნაკლის წყაროები, როგორც წესი, ცალკე remedies-ს იძლევა.',
                'defect_nullity',
                mb_substr($defectNullityWindow, 0, 180),
            );
        }

        $defectChallengeWindow = $this->defectChallengeNoticeWindow($answerText);
        if ($defectChallengeWindow !== null
            && $this->sourceHasDefectNoticeRule($decisions, $matsneResults, $extractedRules)
            && !$this->hasNegation($defectChallengeWindow)
        ) {
            $flags[] = $this->flag(
                'defect_notice_as_challenge_period',
                'medium',
                'პასუხი ნაკლის პრეტენზიის/შეტყობინების წესს "შეცილების ვადად" აყალიბებს; შეცილება მოტყუების/სადავო გარიგების რეჟიმს ეკუთვნის, ნაკლისთვის კი სწორი ტერმინებია პრეტენზია, შეტყობინება ან ხანდაზმულობა.',
                'defect_notice',
                mb_substr($defectChallengeWindow, 0, 180),
            );
        }

        return $flags;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectProceduralConsequenceClaims(string $answerText): array
    {
        return $this->consequenceTaxonomy->boundaryFindings($answerText);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectGenericSourcePlaceholders(string $answerText): array
    {
        $flags = [];
        $lines = preg_split('/\R+/u', $answerText) ?: [$answerText];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $lower = mb_strtolower($trimmed);
            $isNormLine = str_contains($lower, 'კანონი/ნორმა')
                || str_contains($lower, '📕')
                || str_contains($lower, 'წყარო:')
                || str_contains($lower, '**წყარო')
                || str_contains($lower, '**კანონი')
                || str_starts_with($lower, 'ნორმა:');

            if ($this->containsAny($lower, [
                'ზოგადი პრინციპ',
                'ზოგადი სამართლებრივი პრინციპ',
                'მუხლები მოძიებული არ არის',
                'მუხლი მოძიებული არ არის',
            ])) {
                $flags[] = $this->flag(
                    'generic_source_placeholder',
                    'high',
                    'პასუხი კონკრეტული ნორმის ნაცვლად იყენებს ბუნდოვან წყაროს/„მუხლები მოძიებული არ არის“ ფორმულას. დიდ კაზუსში უნდა მიეთითოს კონკრეტული მუხლი ან ფრთხილად ითქვას, რომ კონკრეტული მუხლი მოძიებული წყაროებით არ დასტურდება.',
                    null,
                    mb_substr($trimmed, 0, 180),
                );
                continue;
            }

            if ($isNormLine
                && $this->containsAny($lower, [
                    'საქართველოს სამოქალაქო კოდექსი',
                    'საქართველოს შრომის კოდექსი',
                    'სამოქალაქო კოდექსი',
                    'შრომის კოდექსი',
                    'ადმინისტრაციული წარმოების შესახებ კანონმდებლობა',
                    'ადმინისტრაციული სამართლის ზოგადი პრინციპ',
                    'ადმინისტრაციული სამართალწარმო',
                    'პერსონალურ მონაცემთა დაცვის შესახებ კანონმდებლობა',
                ])
                && !$this->containsAny($lower, ['მუხლი', 'მუხლები', 'მუხ.', '-ე', '№'])
            ) {
                $flags[] = $this->flag(
                    'generic_source_placeholder',
                    'high',
                    'პასუხი წყაროდ ასახელებს მხოლოდ კანონს/კოდექსს კონკრეტული მუხლის ან სპეციალური წესის გარეშე.',
                    null,
                    mb_substr($trimmed, 0, 180),
                );
            }

            if ($isNormLine
                && $this->containsAny($lower, ['სპეციალური ნორმა', 'სპეციალური კანონი'])
                && $this->containsAny($lower, ['არ მოიძებნა', 'ვერ მოიძებნა', 'არ იძებნება'])
            ) {
                $flags[] = $this->flag(
                    'generic_source_placeholder',
                    'high',
                    'პასუხი საკითხს ტოვებს „სპეციალური ნორმა არ მოიძებნა“ ფორმულით. თუ სპეციალური წყარო არ ჩანს, უნდა მიეთითოს რომელი მოძიებული წყაროებით არ დასტურდება და რა ზოგადი ჩარჩო გამოიყენება.',
                    null,
                    mb_substr($trimmed, 0, 180),
                );
            }
        }

        return $flags;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectCivilCode55Misuse(string $answerText): array
    {
        $flags = [];

        foreach ($this->civilCode55Windows($answerText) as $window) {
            $lower = mb_strtolower($window);

            if ($this->isAllowedCivilCode55Use($lower)) {
                continue;
            }

            if (!$this->containsAny($lower, [
                'პირგასამტეხლ',
                'ოფციონ',
                'აქცი',
                'ზიან',
                'მიზეზობრივ',
                'ბრალეულ',
                'ადმინისტრაციულ',
                'ჯარიმ',
                'მონაცემ',
                'გათავისუფლ',
                'ბონუს',
            ])) {
                continue;
            }

            $flags[] = $this->flag(
                'civil_code_55_misuse',
                'high',
                'სამოქალაქო კოდექსის 55-ე მუხლი გამოყენებულია როგორც ზოგადი საფუძველი ისეთ საკითხზე, სადაც საჭიროა სპეციალური ნორმა (მაგ. პირგასამტეხლო, ზიანი, ადმინისტრაციული ჯარიმა, ოფციონი ან შრომითი შედეგი).',
                '55',
                mb_substr(trim($window), 0, 220),
            );
        }

        return $flags;
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @return array<int, array<string, mixed>>
     */
    private function detectPrivacyLawOmission(string $answerText, array $matsneResults): array
    {
        $lower = mb_strtolower($answerText);

        if (!$this->containsAny($lower, ['პერსონალურ მონაცემ', 'მონაცემთა გაჟონ', 'მონაცემთა დაცვის სამსახ', 'მონაცემთა დაცვ'])) {
            return [];
        }

        if (!$this->sourceHasPersonalDataLaw($matsneResults)) {
            return [];
        }

        if ($this->claimsPersonalDataLawNotFound($answerText)) {
            return [
                $this->flag(
                    'privacy_law_source_denied',
                    'high',
                    'პასუხი წერს, რომ პერსონალურ მონაცემთა დაცვის სპეციალური ნორმა ვერ მოიძებნა, თუმცა მოძიებულ წყაროებში არის „პერსონალურ მონაცემთა დაცვის შესახებ“ კანონი.',
                    'personal_data_law',
                ),
            ];
        }

        if (str_contains($lower, 'პერსონალურ მონაცემთა დაცვის შესახებ')) {
            return [];
        }

        return [
            $this->flag(
                'privacy_law_omission',
                'high',
                'პასუხი მსჯელობს პერსონალურ მონაცემებზე, მაგრამ არ იყენებს მოძიებულ სპეციალურ კანონს „პერსონალურ მონაცემთა დაცვის შესახებ“. საკითხი არ უნდა დარჩეს მხოლოდ შრომის/სამოქალაქო ნორმებზე.',
                'personal_data_law',
            ),
        ];
    }

    private function claimsPersonalDataLawNotFound(string $answerText): bool
    {
        $lower = mb_strtolower($answerText);

        return (bool) preg_match(
            '/პერსონალურ მონაცემთა დაცვის შესახებ(?:\s+კანონის მიხედვით)?.{0,180}(?:არ მოიძებნ|არ არის პირდაპირ დადასტურ|არ არის დადასტურ|მუხლები მოძიებული არ არის|მუხლი მოძიებული არ არის)/u',
            $lower,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     */
    private function sourceHasPersonalDataLaw(array $matsneResults): bool
    {
        foreach ($matsneResults as $result) {
            $title = mb_strtolower((string) ($result['title'] ?? ''));
            $text = mb_strtolower((string) ($result['excerpt'] ?? '') . "\n" . (string) ($result['content'] ?? ''));

            if (str_contains($title, 'პერსონალურ მონაცემთა დაცვის შესახებ')
                || str_contains($text, 'პერსონალურ მონაცემთა დაცვის შესახებ')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $answerArticles
     * @param array<int, array<string, mixed>> $matsneResults
     * @return array<int, array<string, mixed>>
     */
    private function detectSpecialSourceOmissions(string $answerText, array $answerArticles, array $matsneResults): array
    {
        $lower = mb_strtolower($answerText);
        $flags = [];

        $expectations = [
            [
                'type' => 'real_estate_registry_source_omission',
                'triggers' => ['საჯარო რეესტრ', 'რეგისტრაცი', 'დაურეგისტრირ', 'უძრავ ნივთ', 'ბინის საკუთრ'],
                'title' => 'სამოქალაქო კოდექსი',
                'articles' => ['183', '185', '311', '312', '323'],
                'message' => 'პასუხი მსჯელობს უძრავი ნივთის რეგისტრაციაზე/რეესტრზე, მაგრამ არ ასახელებს მოძიებულ სპეციალურ მუხლებს უძრავი ნივთის შეძენასა და რეესტრის პრეზუმფციაზე.',
            ],
            [
                'type' => 'mortgage_source_omission',
                'triggers' => ['იპოთეკ'],
                'title' => 'სამოქალაქო კოდექსი',
                'articles' => ['286', '287', '290', '297', '300', '301', '302'],
                'message' => 'პასუხი მსჯელობს იპოთეკაზე/რეალიზაციაზე, მაგრამ არ ასახელებს მოძიებულ სპეციალურ მუხლებს იპოთეკის ცნებაზე, რიგითობაზე ან რეალიზაციაზე.',
            ],
            [
                'type' => 'inheritance_source_omission',
                'triggers' => ['მემკვიდრ', 'სამკვიდრ', 'გარდაიცვალ'],
                'title' => 'სამოქალაქო კოდექსი',
                'articles' => ['1306', '1307', '1319', '1320', '1328', '1336', '1339'],
                'message' => 'პასუხი მსჯელობს მემკვიდრეობაზე/სამკვიდროზე, მაგრამ არ ასახელებს მოძიებულ მემკვიდრეობის სპეციალურ მუხლებს.',
            ],
            [
                'type' => 'marital_property_source_omission',
                'triggers' => ['მეუღლეთა საერთო', 'მეუღლეთა თანასაკუთრ', 'ქორწინების განმავლობაში', 'მეუღლე აცხადებს'],
                'title' => 'სამოქალაქო კოდექსი',
                'articles' => ['1158', '1160', '1161', '1163', '1171'],
                'message' => 'პასუხი მსჯელობს მეუღლეთა ქონებრივ რეჟიმზე, მაგრამ არ ასახელებს მოძიებულ მუხლებს მეუღლეთა თანასაკუთრებაზე.',
            ],
            [
                'type' => 'insolvency_source_omission',
                'triggers' => ['გადახდისუუნარ', 'გაკოტრ', 'კრედიტორ'],
                'title' => 'რეაბილიტაციისა და კრედიტორთა კოლექტიური დაკმაყოფილების შესახებ',
                'articles' => ['1', '3', '5', '6', '52'],
                'message' => 'პასუხი მსჯელობს გადახდისუუნარობაზე/კრედიტორულ სტატუსზე, მაგრამ არ ასახელებს მოძიებულ სპეციალურ გადახდისუუნარობის კანონს ან მის მუხლებს.',
            ],
            [
                'type' => 'criminal_preclusion_source_omission',
                'triggers' => ['სისხლის სამართლის', 'თაღლით', 'განაჩენ', 'პრეიუდიც'],
                'title' => 'სამოქალაქო საპროცესო კოდექსი',
                'articles' => ['106'],
                'message' => 'პასუხი მსჯელობს სისხლის სამართლის განაჩენის გავლენაზე, მაგრამ არ ასახელებს სამოქალაქო საპროცესო კოდექსის პრეიუდიციულობის მუხლს.',
            ],
            [
                'type' => 'joinder_source_omission',
                'triggers' => ['კოლექტიური სარჩელ', 'ერთობლივი სარჩელ', 'რამდენიმე მოსარჩელ'],
                'title' => 'სამოქალაქო საპროცესო კოდექსი',
                'articles' => ['86'],
                'message' => 'პასუხი მსჯელობს რამდენიმე მოსარჩელის ერთობლივ სარჩელზე, მაგრამ არ ასახელებს საპროცესო თანამონაწილეობის მუხლს.',
            ],
        ];

        foreach ($expectations as $expectation) {
            if (!$this->containsAny($lower, $expectation['triggers'])) {
                continue;
            }

            if (!$this->sourceHasAnyArticleForTitle($matsneResults, $expectation['title'], $expectation['articles'])) {
                continue;
            }

            if ((bool) array_intersect($answerArticles, $expectation['articles'])) {
                continue;
            }

            $flags[] = $this->flag(
                $expectation['type'],
                'high',
                $expectation['message'],
                implode(',', $expectation['articles']),
            );
        }

        return $flags;
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, string> $articles
     */
    private function sourceHasAnyArticleForTitle(array $matsneResults, string $titleNeedle, array $articles): bool
    {
        $titleNeedle = mb_strtolower($titleNeedle);

        foreach ($matsneResults as $result) {
            $title = mb_strtolower((string) ($result['title'] ?? ''));
            if (!str_contains($title, $titleNeedle)) {
                continue;
            }

            foreach (['_article_num', 'article_num'] as $key) {
                if (isset($result[$key]) && preg_match('/\d{1,4}/', (string) $result[$key], $match)) {
                    if (in_array((string) (int) $match[0], $articles, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function civilCode55Windows(string $answerText): array
    {
        $windows = [];
        $lowerText = mb_strtolower($answerText);
        $patterns = [
            '/(?:სკ(?:-ის)?|სამოქალაქო კოდექს(?:ი|ის)?|საქართველოს სამოქალაქო კოდექს(?:ი|ის)?).{0,50}(?:55|55-ე)/u',
            '/(?:55|55-ე).{0,50}(?:სკ|სამოქალაქო კოდექს)/u',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $lowerText, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] ?? [] as $match) {
                $offset = (int) ($match[1] ?? 0);
                $window = substr($lowerText, $offset, strlen((string) $match[0]) + 520);
                if (!$this->hasCivilCode55Negation($window)) {
                    $windows[] = trim($window);
                }
            }
        }

        return array_values(array_unique($windows));
    }

    private function hasCivilCode55Negation(string $window): bool
    {
        return (bool) preg_match(
            '/(?:55|55-ე|სამოქალაქო კოდექს(?:ი|ის)?).{0,80}(?:არ გამოიყენ|არ არის რელევანტ|არ ვრცელდ|არ წარმოადგენს|არ უნდა გამოიყენ)/u',
            $window,
        );
    }

    private function isAllowedCivilCode55Use(string $lowerWindow): bool
    {
        if (str_contains($lowerWindow, 'პირგასამტეხლ')) {
            return false;
        }

        return $this->containsAny($lowerWindow, [
            'ამორალ',
            'ზნეობ',
            'გარიგებ',
            'შესრულებათა',
            'საპასუხო შესრულ',
            'მძიმე მდგომარეობ',
            'გულუბრყვილ',
            'გამოუცდელ',
            'აშკარა შეუსაბამ',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function detectOutcomeCategories(string $text): array
    {
        $lower = mb_strtolower($text);
        $outcomes = [];

        foreach (self::REMEDY_OUTCOME_KEYWORDS as $key => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    $outcomes[] = $key;
                    break;
                }
            }
        }

        return array_values(array_unique($outcomes));
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $echrResults
     * @param array<int, array<string, mixed>> $extractedRules
     * @return array<int, string>
     */
    private function detectSourceOutcomeCategories(
        array $decisions,
        array $matsneResults,
        array $echrResults,
        array $extractedRules,
    ): array {
        $sourceText = implode("\n", array_filter([
            $this->joinFields($matsneResults, ['title', 'excerpt', 'content', 'text']),
            $this->joinFields($decisions, ['excerpt', 'full_text', 'content']),
            $this->joinFields($echrResults, ['title', 'excerpt', 'summary', 'content']),
            $this->joinNestedStrings($extractedRules),
        ]));

        return $this->detectOutcomeCategories($sourceText);
    }

    private function defectNullityWindow(string $answerText): ?string
    {
        $sentences = preg_split('/(?<=[.?!])\s+|\R+/u', $answerText) ?: [$answerText];
        foreach ($sentences as $sentence) {
            $lower = mb_strtolower(trim($sentence));
            if ($lower === '') {
                continue;
            }

            if (str_contains($lower, 'ბათილ')
                && str_contains($lower, 'ნაკლ')
                && $this->containsAny($lower, ['საფუძველ', 'გამო', 'როგორც', '491'])
            ) {
                return trim($sentence);
            }

            if (str_contains($lower, 'ბათილ') && preg_match('/(?:სკ(?:-ის)?\s*)?491/u', $lower)) {
                return trim($sentence);
            }
        }

        $patterns = [
            '/[^.?!\n]*(?:ნაკლ\p{L}*.{0,80}(?:საფუძველ|გამო|როგორც).{0,80}ბათილ\p{L}*)[^.?!\n]*/u',
            '/[^.?!\n]*(?:ბათილ\p{L}*.{0,80}ნაკლ\p{L}*.{0,80}(?:საფუძველ|გამო|როგორც))[^.?!\n]*/u',
            '/[^.?!\n]*(?:ბათილ\p{L}*.{0,140}(?:ნაკლ\p{L}*|491).{0,100}(?:საფუძველ|491))[^.?!\n]*/u',
            '/[^.?!\n]*(?:(?:ნაკლ\p{L}*|491).{0,100}(?:საფუძველ|491).{0,140}ბათილ\p{L}*)[^.?!\n]*/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $answerText, $match)) {
                return trim($match[0]);
            }
        }

        return null;
    }

    private function defectChallengeNoticeWindow(string $answerText): ?string
    {
        $sentences = preg_split('/(?<=[.?!])\s+|\R+/u', $answerText) ?: [$answerText];
        foreach ($sentences as $sentence) {
            $lower = mb_strtolower(trim($sentence));
            if ($lower === '') {
                continue;
            }

            if (str_contains($lower, 'შეცილ')
                && $this->containsAny($lower, ['ნაკლ', 'დაფარულ', '491', '495'])
            ) {
                return trim($sentence);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $extractedRules
     */
    private function sourceHasDefectRemedies(array $decisions, array $matsneResults, array $extractedRules): bool
    {
        $sourceText = mb_strtolower(implode("\n", array_filter([
            $this->joinFields($matsneResults, ['title', 'excerpt', 'content', 'text']),
            $this->joinFields($decisions, ['excerpt', 'full_text', 'content']),
            $this->joinNestedStrings($extractedRules),
        ])));

        return str_contains($sourceText, 'ნაკლ')
            && $this->containsAny($sourceText, ['მოშლ', 'ფასის შემცირ', 'გამოსწორ', 'შეცვალ', 'ზიან', 'პრეტენზ']);
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $extractedRules
     */
    private function sourceHasDefectNoticeRule(array $decisions, array $matsneResults, array $extractedRules): bool
    {
        $sourceText = mb_strtolower(implode("\n", array_filter([
            $this->joinFields($matsneResults, ['title', 'excerpt', 'content', 'text']),
            $this->joinFields($decisions, ['excerpt', 'full_text', 'content']),
            $this->joinNestedStrings($extractedRules),
        ])));

        return str_contains($sourceText, 'ნაკლ')
            && $this->containsAny($sourceText, ['პრეტენზ', 'აცნობ', 'ეცნობ', 'ერთმევა', 'უფლების დაკარგ']);
    }

    private function hasNegation(string $text): bool
    {
        $lower = mb_strtolower($text);

        return $this->containsAny($lower, ['არ არის', 'არაა', 'და არა', 'ვერ', 'არ იწვევს', 'არ ნიშნავს', 'არ წარმოადგენს']);
    }

    private function isNegatedOutcome(string $text, string $outcome): bool
    {
        $needles = self::REMEDY_OUTCOME_KEYWORDS[$outcome] ?? [];
        $lower = mb_strtolower($text);

        foreach ($needles as $needle) {
            $pos = mb_stripos($lower, $needle);
            if ($pos === false) {
                continue;
            }

            $window = mb_substr($lower, max(0, $pos - 45), mb_strlen($needle) + 90);
            if ($this->hasNegation($window)) {
                return true;
            }
        }

        return false;
    }

    private function hasNegativeCaseLawStatement(string $lower): bool
    {
        return (bool) (
            preg_match('/(პრაქტიკ|გადაწყვეტილებ|საქმე|უზენაეს).{0,60}(ვერ|არ)\s+(მოიძებნ|არის|დასტურდ|გვაქვს|მომეპოვება)/u', $lower)
            || preg_match('/(ვერ|არ)\s+(მოიძებნ|არის|დასტურდ|გვაქვს|მომეპოვება).{0,60}(პრაქტიკ|გადაწყვეტილებ|საქმე|უზენაეს)/u', $lower)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     */
    private function hasPrimaryCourtAuthority(array $decisions): bool
    {
        foreach ($decisions as $decision) {
            if (($decision['answer_role'] ?? null) === 'primary'
                && !in_array('weak_context_match', $decision['quality_flags'] ?? [], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $fields
     */
    private function joinFields(array $items, array $fields): string
    {
        $parts = [];

        foreach ($items as $item) {
            foreach ($fields as $field) {
                if (isset($item[$field]) && is_scalar($item[$field])) {
                    $parts[] = (string) $item[$field];
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function joinNestedStrings(array $value): string
    {
        $parts = [];

        array_walk_recursive($value, function (mixed $item) use (&$parts): void {
            if (is_scalar($item)) {
                $parts[] = (string) $item;
            }
        });

        return implode("\n", $parts);
    }

    /**
     * @param array<int, string> $haystack
     */
    private function containsAny(string $text, array $haystack): bool
    {
        foreach ($haystack as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, fn (string $value) => $value !== '')));
        usort($values, fn (string $a, string $b) => (float) $a <=> (float) $b ?: strcmp($a, $b));

        return $values;
    }

    private function normalizeNumber(string $value): string
    {
        $value = preg_replace('/(?:\s|\x{00A0})+/u', '', $value) ?? $value;
        $normalized = str_replace(',', '.', $value);

        if (str_contains($normalized, '.')) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function flag(string $type, string $severity, string $message, ?string $value = null, ?string $snippet = null): array
    {
        return array_filter([
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'value' => $value,
            'snippet' => $snippet,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param array<int, array<string, mixed>> $flags
     */
    private function score(array $flags): int
    {
        $deduct = [
            'high' => 30,
            'medium' => 15,
            'low' => 5,
        ];

        $score = 100;
        foreach ($flags as $flag) {
            $score -= $deduct[$flag['severity']] ?? 5;
        }

        return max(0, $score);
    }

    /**
     * @param array<int, array<string, mixed>> $flags
     */
    private function verdict(array $flags, int $score): string
    {
        $hasHighFlag = (bool) array_filter($flags, fn (array $flag) => $flag['severity'] === 'high');

        if ($hasHighFlag || $score < 60) {
            return 'fail';
        }

        if (!empty($flags) || $score < 85) {
            return 'warn';
        }

        return 'pass';
    }
}
