<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LLM-based reranker using GPT-4.1-mini.
 *
 * Takes top N candidate decisions (already reconstructed with excerpts),
 * asks the model to rank them by relevance to the user query,
 * returns reordered top-K array.
 *
 * Fails safe: on any error returns original order truncated to topK.
 */
class RerankerService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private OpenAIUsageTrackerService $usageTracker;

    public function __construct(?OpenAIUsageTrackerService $usageTracker = null)
    {
        $this->usageTracker = $usageTracker ?? app(OpenAIUsageTrackerService::class);
        $this->apiKey  = config('openai.api_key');
        $this->model   = config('openai.extraction_model', 'gpt-4.1-mini');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    /**
     * Reranks candidate decisions by relevance to the query.
     *
     * @param  string  $query     Original user question
     * @param  array   $decisions Reconstructed decisions from retriever
     * @param  int     $topK      How many to keep after reranking
     * @param  string  $mode      'advocate' reserves 1 slot for minority opinion
     * @return array   Reordered and truncated decisions
     */
    public function rerank(string $query, array $decisions, int $topK = 5, string $mode = 'explain'): array
    {
        if (count($decisions) <= $topK) {
            return $decisions;
        }

        [$pinned, $decisions] = $this->splitPinnedDecisions($decisions, $topK);
        if (count($pinned) >= $topK) {
            return array_slice($pinned, 0, $topK);
        }
        $remainingSlots = $topK - count($pinned);

        // advocate mode: outlier/minority case-ი სავალდ. შეინახე top-K-ში
        if ($mode === 'advocate') {
            return array_merge($pinned, $this->rerankAdvocate($query, $decisions, $remainingSlots));
        }

        // Build lookup by case_id
        $byId = [];
        foreach ($decisions as $d) {
            $byId[$d['case_id']] = $d;
        }

        $prompt = $this->buildPrompt($query, $decisions);

        try {
            $response = Http::retry(2, 500, fn($e) =>
                $e instanceof RequestException
                && in_array($e->response?->status(), [500, 502, 503, 529])
            )
                ->withToken($this->apiKey)
                ->timeout(20)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => 80,
                    'messages'    => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user',   'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Reranker: API error, falling back', [
                    'status' => $response->status(),
                ]);
                return array_merge($pinned, array_slice($decisions, 0, $remainingSlots));
            }

            $this->usageTracker->recordChat('reranking', $this->model, $response->json('usage') ?? null);

            $content   = trim($response->json('choices.0.message.content') ?? '');
            $rankedIds = $this->parseIds($content);

            if (empty($rankedIds)) {
                Log::warning('Reranker: could not parse response', ['content' => $content]);
                return array_merge($pinned, array_slice($decisions, 0, $remainingSlots));
            }

            // Build reranked list from parsed ids
            $reranked = [];
            foreach ($rankedIds as $id) {
                if (isset($byId[$id]) && count($reranked) < $remainingSlots) {
                    $reranked[] = $byId[$id];
                    unset($byId[$id]);
                }
            }

            // Fill to topK from original order if reranker returned fewer
            foreach ($decisions as $d) {
                if (count($reranked) >= $remainingSlots) break;
                if (isset($byId[$d['case_id']])) {
                    $reranked[] = $d;
                }
            }

            Log::debug('Reranker: done', [
                'input'      => count($decisions),
                'output'     => count($reranked),
                'ranked_ids' => $rankedIds,
            ]);

            return array_merge($pinned, $reranked);

        } catch (\Throwable $e) {
            Log::warning('Reranker: exception, falling back — ' . $e->getMessage());
            return array_merge($pinned, array_slice($decisions, 0, $remainingSlots));
        }
    }

    /**
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    private function splitPinnedDecisions(array $decisions, int $topK): array
    {
        $pinned = [];
        $regular = [];

        foreach ($decisions as $decision) {
            $sources = $decision['match_sources'] ?? [];
            $relevance = (float) ($decision['relevance_score'] ?? 0.0);
            $semantic = $decision['semantic_relevance'] ?? [];
            $semanticHigh = ($semantic['confidence'] ?? null) === 'high'
                && (float) ($decision['semantic_relevance_score'] ?? 0.0) >= 50.0;
            $isPinned = in_array('case_number', $sources, true)
                || in_array('fingerprint', $sources, true)
                || (in_array('pasted_text', $sources, true) && $relevance >= 0.95)
                || $this->isTopExactCaseCardIssue($decision)
                || $semanticHigh;

            if ($isPinned) {
                $pinned[] = $decision;
            } else {
                $regular[] = $decision;
            }
        }

        usort($pinned, function (array $a, array $b) {
            $priorityCompare = $this->pinPriority($b) <=> $this->pinPriority($a);

            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            $rankCompare = $this->pinRetrievalRank($a) <=> $this->pinRetrievalRank($b);
            if ($rankCompare !== 0) {
                return $rankCompare;
            }

            $semanticCompare = ($b['semantic_relevance_score'] ?? 0) <=> ($a['semantic_relevance_score'] ?? 0);
            if ($semanticCompare !== 0) {
                return $semanticCompare;
            }

            return ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
        });

        return [array_slice($pinned, 0, $topK), $regular];
    }

    private function pinPriority(array $decision): int
    {
        $sources = $decision['match_sources'] ?? [];

        if (in_array('case_number', $sources, true)) {
            return 3;
        }
        if (in_array('fingerprint', $sources, true)) {
            return 2;
        }
        if (in_array('pasted_text', $sources, true)) {
            return 1;
        }
        if ($this->isTopExactCaseCardIssue($decision)) {
            return 1;
        }
        if (($decision['semantic_relevance']['confidence'] ?? null) === 'high') {
            return 1;
        }

        return 0;
    }

    private function isTopExactCaseCardIssue(array $decision): bool
    {
        $sources = $decision['match_sources'] ?? [];
        $rank = (int) ($decision['retrieval_rank'] ?? 0);

        return in_array('case_card_keyword', $sources, true)
            && ($decision['semantic_relevance']['case_card_legal_issue_exact'] ?? false) === true
            && $rank > 0
            && $rank <= 5;
    }

    private function pinRetrievalRank(array $decision): int
    {
        return $this->isTopExactCaseCardIssue($decision)
            ? (int) ($decision['retrieval_rank'] ?? PHP_INT_MAX)
            : PHP_INT_MAX;
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are a legal relevance judge for Georgian court decisions.
Given a legal query and candidate court decisions, rank by substantive legal analogy.

Priority order:
1. Same legal issue / procedural question.
2. Same material fact pattern.
3. Same applied norm/article.
4. Same holding or court reasoning.
5. Retrieval score, recency, and court authority only as tie-breakers.

Do not prefer a case merely because it shares generic words.
Do not drop a high-confidence structured relevance candidate unless another case is clearly closer on the legal issue.

Output ONLY the IDs of the most relevant decisions in order (most relevant first).
Format: comma-separated integer IDs only. Example: 12345,6789,1011
No explanation. No other text. Just IDs.
PROMPT;
    }

    private function buildPrompt(string $query, array $decisions): string
    {
        $lines = "QUERY: {$query}\n\nCANDIDATE DECISIONS:\n";

        foreach ($decisions as $d) {
            $rawDate  = $d['case_date'] ?? null;
            $date     = $rawDate instanceof \Carbon\Carbon
                ? $rawDate->format('Y-m-d')
                : ($rawDate ?? 'N/A');
            $card = is_array($d['case_card'] ?? null) ? $d['case_card'] : [];
            $articles = $card['applied_articles'] ?? [];
            if (is_array($articles)) {
                $articles = implode('; ', array_slice($articles, 0, 10));
            }
            $semantic = $d['semantic_relevance'] ?? [];
            $semanticLine = sprintf(
                'Structured relevance: %s/100 | confidence=%s | issue=%s holding=%s facts=%s articles=%s procedure=%s | reason=%s',
                $d['semantic_relevance_score'] ?? 'N/A',
                $semantic['confidence'] ?? 'N/A',
                $semantic['legal_issue_match'] ?? 'N/A',
                $semantic['holding_match'] ?? 'N/A',
                $semantic['fact_pattern_match'] ?? 'N/A',
                $semantic['article_match'] ?? 'N/A',
                $semantic['procedural_match'] ?? 'N/A',
                $d['ranking_explanation'] ?? 'N/A',
            );
            $excerpt  = mb_substr($d['excerpt'] ?? $d['full_text'] ?? '', 0, 700);
            $lines   .= sprintf(
                "[ID:%d] %s | %s | %s | %s | %s | retrieval_rank=%s\n%s\nLegal issue: %s\nHolding: %s\nApplied articles: %s\nExcerpt: %s\n\n",
                $d['case_id'],
                $d['case_num']        ?? 'N/A',
                $date,
                $d['category']        ?? 'N/A',
                $d['dispute_subject'] ?? 'N/A',
                $d['result']          ?? 'N/A',
                $d['retrieval_rank']   ?? 'N/A',
                $semanticLine,
                $card['legal_issue'] ?? 'N/A',
                $card['holding'] ?? 'N/A',
                $articles ?: 'N/A',
                $excerpt,
            );
        }

        $lines .= "\nReturn IDs of the most relevant decisions, most relevant first:";

        return $lines;
    }

    private function parseIds(string $content): array
    {
        preg_match_all('/\b(\d{3,})\b/', $content, $m);
        return array_unique(array_map('intval', $m[1]));
    }

    /**
     * Advocate rerank:
     *  - 1 slot reserved for minority/outlier opinion (advocate_value flag)
     *  - remaining slots filled by standard LLM rerank
     */
    private function rerankAdvocate(string $query, array $decisions, int $topK): array
    {
        // outlier case-ები გამოვყოთ
        $outliers   = array_values(array_filter(
            $decisions,
            fn($d) => in_array('advocate_value', $d['quality_flags'] ?? [])
        ));
        $mainstream = array_values(array_filter(
            $decisions,
            fn($d) => !in_array('advocate_value', $d['quality_flags'] ?? [])
        ));

        // mainstream-ს ვარეინქავთ LLM-ით (topK-1 slot-ისთვის)
        $mainstreamSlots = $topK - min(1, count($outliers));
        $rankedMainstream = count($mainstream) <= $mainstreamSlots
            ? $mainstream
            : $this->rerank($query, $mainstream, $mainstreamSlots);

        // ერთი outlier ბოლოს (context-ში minority opinion-ად)
        $result = $rankedMainstream;
        if (!empty($outliers)) {
            $result[] = $outliers[0]; // ყველაზე რელევანტური outlier
        }

        Log::debug('Reranker: advocate mode', [
            'mainstream' => count($rankedMainstream),
            'outlier'    => !empty($outliers) ? 1 : 0,
            'total'      => count($result),
        ]);

        return $result;
    }
}
