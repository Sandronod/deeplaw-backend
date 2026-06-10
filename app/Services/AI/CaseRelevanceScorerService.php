<?php

namespace App\Services\AI;

use App\DTOs\IssueList;
use Illuminate\Support\Facades\Log;

/**
 * Scores retrieved court decisions by legal-content relevance.
 *
 * Retrieval gives us plausible candidates. This scorer decides which candidate
 * is most analogous to the user's question by comparing the legal issue,
 * holding, fact pattern, applied articles, and procedural posture.
 */
class CaseRelevanceScorerService
{
    private const DIRECT_SOURCE_SCORES = [
        'case_number' => 99.0,
        'fingerprint' => 97.0,
        'pasted_text' => 96.0,
    ];

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @return array<int, array<string, mixed>>
     */
    public function score(string $query, array $decisions, ?IssueList $issueList = null): array
    {
        if (empty($decisions)) {
            return $decisions;
        }

        $queryProfile = $this->profileQuery($query, $issueList);

        foreach ($decisions as &$decision) {
            $breakdown = $this->scoreDecision($queryProfile, $decision);
            $decision['semantic_relevance_score'] = round($breakdown['total'], 2);
            $decision['semantic_relevance'] = $breakdown;
            $decision['ranking_explanation'] = $this->explain($breakdown);
        }
        unset($decision);

        usort($decisions, function (array $a, array $b) {
            $direct = $this->directPriority($b) <=> $this->directPriority($a);
            if ($direct !== 0) {
                return $direct;
            }

            $semantic = ($b['semantic_relevance_score'] ?? 0) <=> ($a['semantic_relevance_score'] ?? 0);
            if ($semantic !== 0) {
                return $semantic;
            }

            $combined = ($b['combined_score'] ?? 0) <=> ($a['combined_score'] ?? 0);
            if ($combined !== 0) {
                return $combined;
            }

            return ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
        });

        $decisions = $this->markConfidence($decisions);

        Log::debug('CaseRelevanceScorer: scored', [
            'count' => count($decisions),
            'top_case_id' => $decisions[0]['case_id'] ?? null,
            'top_case_num' => $decisions[0]['case_num'] ?? null,
            'top_score' => $decisions[0]['semantic_relevance_score'] ?? null,
            'top_reason' => $decisions[0]['ranking_explanation'] ?? null,
        ]);

        return $decisions;
    }

    /**
     * @return array<string, mixed>
     */
    private function profileQuery(string $query, ?IssueList $issueList): array
    {
        $issueText = $query;
        if ($issueList && !empty($issueList->issues)) {
            $issueText .= "\n" . implode("\n", array_map(
                fn ($issue) => is_object($issue)
                    ? trim((string) ($issue->title ?? '') . ' ' . implode(' ', (array) ($issue->keywords ?? [])))
                    : (is_array($issue) ? (string) ($issue['title'] ?? $issue['issue'] ?? '') : (string) $issue),
                $issueList->issues,
            ));
        }

        return [
            'text' => $query,
            'tokens' => $this->tokens($issueText),
            'articles' => $this->articleRefs($query),
            'concepts' => $this->conceptTags($issueText),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private function scoreDecision(array $query, array $decision): array
    {
        $card = $this->caseCard($decision['case_card'] ?? null);
        $cardLegalIssueText = (string) ($card['legal_issue'] ?? '');
        $caseCardLegalIssueExact = $this->sameNormalizedText((string) ($query['text'] ?? ''), $cardLegalIssueText);

        $legalIssueText = implode(' ', array_filter([
            $cardLegalIssueText,
            $decision['category'] ?? null,
            $decision['dispute_subject'] ?? null,
            $decision['claim_type'] ?? null,
        ]));

        $holdingText = implode(' ', array_filter([
            $card['holding'] ?? null,
            $decision['result'] ?? null,
        ]));

        $factText = implode(' ', array_filter([
            $decision['dispute_subject'] ?? null,
            $decision['category'] ?? null,
            $decision['claim_type'] ?? null,
            $decision['kind'] ?? null,
            mb_substr((string) ($decision['excerpt'] ?? ''), 0, 1800),
        ]));

        $candidateArticles = $this->candidateArticleRefs($card);
        $candidateConcepts = $this->conceptTags($legalIssueText . ' ' . $holdingText . ' ' . $factText);

        $issueSimilarity = max(
            $this->textSimilarity($query['tokens'], $this->tokens($cardLegalIssueText)),
            $this->textSimilarity($query['tokens'], $this->tokens($legalIssueText)),
        );

        $issueScore = $issueSimilarity * 40.0;
        $holdingScore = $this->textSimilarity($query['tokens'], $this->tokens($holdingText)) * 20.0;
        $factScore = $this->textSimilarity($query['tokens'], $this->tokens($factText)) * 15.0;
        $articleScore = $this->articleScore($query['articles'], $candidateArticles) * 10.0;
        $procedureScore = $this->conceptScore($query['concepts'], $candidateConcepts) * 5.0;
        $retrievalScore = min(1.0, max(0.0, (float) ($decision['relevance_score'] ?? 0.0))) * 7.0;
        $retrievalRankScore = $this->retrievalRankScore((int) ($decision['retrieval_rank'] ?? 0));
        $authorityScore = min(1.0, max(0.0, (float) ($decision['combined_score'] ?? 0.0))) * 3.0;

        $total = $issueScore
            + $holdingScore
            + $factScore
            + $articleScore
            + $procedureScore
            + $retrievalScore
            + $retrievalRankScore
            + $authorityScore;

        $directScore = $this->directSourceScore($decision);
        if ($directScore !== null) {
            $total = max($total, $directScore);
        }

        return [
            'total' => min(100.0, round($total, 4)),
            'legal_issue_match' => round($issueScore, 2),
            'holding_match' => round($holdingScore, 2),
            'fact_pattern_match' => round($factScore, 2),
            'article_match' => round($articleScore, 2),
            'procedural_match' => round($procedureScore, 2),
            'retrieval_signal' => round($retrievalScore, 2),
            'retrieval_rank_signal' => round($retrievalRankScore, 2),
            'authority_signal' => round($authorityScore, 2),
            'direct_match_boost' => $directScore,
            'case_card_legal_issue_exact' => $caseCardLegalIssueExact,
            'matched_articles' => array_values(array_intersect($query['articles'], $candidateArticles)),
            'matched_concepts' => array_values(array_intersect($query['concepts'], $candidateConcepts)),
            'confidence' => 'low',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @return array<int, array<string, mixed>>
     */
    private function markConfidence(array $decisions): array
    {
        $topScore = (float) ($decisions[0]['semantic_relevance_score'] ?? 0.0);
        $secondScore = (float) ($decisions[1]['semantic_relevance_score'] ?? 0.0);
        $lead = $topScore - $secondScore;

        foreach ($decisions as $index => &$decision) {
            $score = (float) ($decision['semantic_relevance_score'] ?? 0.0);
            $directPriority = $this->directPriority($decision);

            $confidence = match (true) {
                $directPriority >= 2 => 'high',
                $index === 0 && $score >= 70.0 && $lead >= 8.0 => 'high',
                $index === 0 && $score >= 50.0 && $lead >= 10.0 => 'high',
                $index === 0 && $score >= 45.0 && $lead >= 15.0 => 'high',
                $score >= 45.0 => 'medium',
                default => 'low',
            };

            $decision['semantic_relevance']['confidence'] = $confidence;
            $decision['semantic_relevance']['rank'] = $index + 1;
            $decision['semantic_relevance']['lead_over_next'] = $index === 0 ? round($lead, 2) : null;
        }
        unset($decision);

        return $decisions;
    }

    private function textSimilarity(array $queryTokens, array $candidateTokens): float
    {
        if (empty($queryTokens) || empty($candidateTokens)) {
            return 0.0;
        }

        $querySet = array_fill_keys(array_slice(array_values(array_unique($queryTokens)), 0, 35), true);
        $candidateSet = array_fill_keys(array_values(array_unique($candidateTokens)), true);

        $common = 0;
        foreach ($querySet as $token => $_) {
            if (isset($candidateSet[$token])) {
                $common++;
            }
        }

        if ($common === 0) {
            return 0.0;
        }

        $queryCoverage = $common / max(1, count($querySet));
        $candidateCoverage = $common / max(1, count($candidateSet));
        $jaccard = $common / max(1, count($querySet) + count($candidateSet) - $common);

        return min(1.0, 0.55 * $queryCoverage + 0.35 * $candidateCoverage + 0.10 * $jaccard);
    }

    private function articleScore(array $queryArticles, array $candidateArticles): float
    {
        if (empty($queryArticles)) {
            return 0.0;
        }

        $matches = array_intersect($queryArticles, $candidateArticles);

        return count($matches) / count($queryArticles);
    }

    private function conceptScore(array $queryConcepts, array $candidateConcepts): float
    {
        if (empty($queryConcepts) || empty($candidateConcepts)) {
            return 0.0;
        }

        $matches = array_intersect($queryConcepts, $candidateConcepts);

        return count($matches) / count($queryConcepts);
    }

    private function retrievalRankScore(int $rank): float
    {
        return match (true) {
            $rank === 1 => 8.0,
            $rank === 2 => 6.0,
            $rank === 3 => 4.5,
            $rank <= 5 && $rank > 0 => 3.0,
            $rank <= 8 && $rank > 0 => 1.5,
            default => 0.0,
        };
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $normalized = mb_strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];
        $stop = [
            'და', 'ან', 'თუ', 'რომ', 'არის', 'იყო', 'იქნა', 'საქმე', 'საქმეზე', 'საკითხი',
            'შესახებ', 'შემდეგი', 'წლის', 'მიერ', 'ასევე', 'ყველა', 'რომელიც', 'ამ',
            'სასამართლო', 'სასამართლოს', 'საქართველოს', 'გადაწყვეტილება', 'საკასაციო',
            'სააპელაციო', 'პირველი', 'ინსტანციის', 'მოსარჩელე', 'მოპასუხე',
        ];
        $stopSet = array_fill_keys($stop, true);

        $tokens = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if ($token === '') {
                continue;
            }
            if (ctype_digit($token)) {
                if (mb_strlen($token) >= 2) {
                    $tokens[] = $token;
                }
                continue;
            }
            if (mb_strlen($token) < 4 || isset($stopSet[$token])) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return array<int, string>
     */
    private function articleRefs(string $text): array
    {
        $refs = [];

        preg_match_all('/(?:მუხლ\p{L}*|სსკ|ასკ|ზაკ|სშკ|სსსკ)\D{0,16}(\d{1,4})/u', $text, $matches);
        foreach ($matches[1] ?? [] as $num) {
            $refs[] = ltrim($num, '0');
        }

        preg_match_all('/(\d{1,4})(?:-?ე)?\s+მუხლ/u', $text, $reverse);
        foreach ($reverse[1] ?? [] as $num) {
            $refs[] = ltrim($num, '0');
        }

        return array_values(array_unique(array_filter($refs, fn (string $ref) => $ref !== '')));
    }

    /**
     * @param array<string, mixed> $card
     * @return array<int, string>
     */
    private function candidateArticleRefs(array $card): array
    {
        $articles = $card['applied_articles'] ?? [];
        if (!is_array($articles)) {
            $articles = [$articles];
        }

        $refs = [];
        foreach ($articles as $article) {
            preg_match_all('/\b(\d{1,4})\b/u', (string) $article, $matches);
            foreach ($matches[1] ?? [] as $num) {
                $refs[] = ltrim($num, '0');
            }
        }

        return array_values(array_unique(array_filter($refs, fn (string $ref) => $ref !== '')));
    }

    /**
     * @return array<int, string>
     */
    private function conceptTags(string $text): array
    {
        $lower = mb_strtolower($text);
        $tags = [];
        $map = [
            'cassation' => ['საკასაციო', 'კასატორ', 'კასაცი'],
            'appeal' => ['სააპელაციო', 'აპელაცი'],
            'admissibility' => ['დასაშვებ', 'დაუშვებ', 'დაშვებ'],
            'state_duty' => ['ბაჟ', 'სახელმწიფო ბაჟ'],
            'limitation' => ['ხანდაზმულ', 'ვადა'],
            'admin_act' => ['ადმინისტრაციულ-სამართლებრივ', 'ადმინისტრაციული აქტ', 'აქტის ბათილ'],
            'nullity' => ['ბათილ', 'არარა'],
            'obligation_action' => ['ქმედების განხორციელ', 'დავალ', 'ვალდებულ'],
            'damages' => ['ზიან', 'ანაზღაურ'],
            'labor' => ['შრომ', 'დასაქმებულ', 'დამსაქმებელ'],
            'termination' => ['გათავისუფლ', 'შეწყვეტ'],
            'inheritance' => ['მემკვიდრ'],
            'property' => ['საკუთრ', 'მფლობელ', 'უძრავ'],
            'family' => ['განქორწინ', 'ალიმენტ', 'შვილ'],
            'evidence' => ['მტკიცებულ'],
            'jurisdiction' => ['განსჯად', 'ქვემდებარ'],
            'complaint' => ['საჩივარ', 'სარჩელ'],
            'procurement' => ['შესყიდვ'],
            'social' => ['სოციალურ', 'პენსი', 'შემწეობ'],
        ];

        foreach ($map as $tag => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return array<string, mixed>
     */
    private function caseCard(mixed $card): array
    {
        if (is_array($card)) {
            return $card;
        }
        if (is_string($card) && $card !== '') {
            $decoded = json_decode($card, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function sameNormalizedText(string $left, string $right): bool
    {
        $left = $this->normalizedText($left);
        $right = $this->normalizedText($right);

        return $left !== '' && $left === $right;
    }

    private function normalizedText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function directSourceScore(array $decision): ?float
    {
        $sources = $decision['match_sources'] ?? [];
        foreach (self::DIRECT_SOURCE_SCORES as $source => $score) {
            if (!in_array($source, $sources, true)) {
                continue;
            }
            if ($source === 'pasted_text' && (float) ($decision['relevance_score'] ?? 0.0) < 0.95) {
                continue;
            }
            return $score;
        }

        return null;
    }

    private function directPriority(array $decision): int
    {
        $sources = $decision['match_sources'] ?? [];

        if (in_array('case_number', $sources, true)) {
            return 3;
        }
        if (in_array('fingerprint', $sources, true)) {
            return 2;
        }
        if (in_array('pasted_text', $sources, true) && (float) ($decision['relevance_score'] ?? 0.0) >= 0.95) {
            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $breakdown
     */
    private function explain(array $breakdown): string
    {
        $parts = [];

        if (($breakdown['direct_match_boost'] ?? null) !== null) {
            $parts[] = 'direct match';
        }
        if (($breakdown['case_card_legal_issue_exact'] ?? false) === true) {
            $parts[] = 'exact case-card issue';
        }
        if (($breakdown['legal_issue_match'] ?? 0) >= 12) {
            $parts[] = 'legal issue';
        }
        if (($breakdown['holding_match'] ?? 0) >= 6) {
            $parts[] = 'holding';
        }
        if (($breakdown['fact_pattern_match'] ?? 0) >= 5) {
            $parts[] = 'facts';
        }
        if (!empty($breakdown['matched_articles'])) {
            $parts[] = 'articles: ' . implode(', ', $breakdown['matched_articles']);
        }
        if (!empty($breakdown['matched_concepts'])) {
            $parts[] = 'concepts: ' . implode(', ', $breakdown['matched_concepts']);
        }
        if (($breakdown['retrieval_rank_signal'] ?? 0) >= 4.5) {
            $parts[] = 'top retrieval candidate';
        }

        return empty($parts) ? 'weak structured match' : implode('; ', $parts);
    }
}
