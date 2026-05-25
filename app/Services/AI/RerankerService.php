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

    public function __construct()
    {
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

        // advocate mode: outlier/minority case-ი სავალდ. შეინახე top-K-ში
        if ($mode === 'advocate') {
            return $this->rerankAdvocate($query, $decisions, $topK);
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
                return array_slice($decisions, 0, $topK);
            }

            $content   = trim($response->json('choices.0.message.content') ?? '');
            $rankedIds = $this->parseIds($content);

            if (empty($rankedIds)) {
                Log::warning('Reranker: could not parse response', ['content' => $content]);
                return array_slice($decisions, 0, $topK);
            }

            // Build reranked list from parsed ids
            $reranked = [];
            foreach ($rankedIds as $id) {
                if (isset($byId[$id]) && count($reranked) < $topK) {
                    $reranked[] = $byId[$id];
                    unset($byId[$id]);
                }
            }

            // Fill to topK from original order if reranker returned fewer
            foreach ($decisions as $d) {
                if (count($reranked) >= $topK) break;
                if (isset($byId[$d['case_id']])) {
                    $reranked[] = $d;
                }
            }

            Log::debug('Reranker: done', [
                'input'      => count($decisions),
                'output'     => count($reranked),
                'ranked_ids' => $rankedIds,
            ]);

            return $reranked;

        } catch (\Throwable $e) {
            Log::warning('Reranker: exception, falling back — ' . $e->getMessage());
            return array_slice($decisions, 0, $topK);
        }
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are a legal relevance judge for Georgian court decisions.
Given a legal query and a list of court decisions with their IDs and summaries,
output ONLY the IDs of the most relevant decisions in order (most relevant first).
Format: comma-separated integer IDs only. Example: 12345,6789,1011
No explanation. No other text. Just IDs.
PROMPT;
    }

    private function buildPrompt(string $query, array $decisions): string
    {
        $lines = "QUERY: {$query}\n\nCANDIDATE DECISIONS:\n";

        foreach ($decisions as $d) {
            $date     = $d['case_date'] instanceof \Carbon\Carbon
                ? $d['case_date']->format('Y-m-d')
                : ($d['case_date'] ?? 'N/A');
            $excerpt  = mb_substr($d['excerpt'] ?? $d['full_text'] ?? '', 0, 350);
            $lines   .= sprintf(
                "[ID:%d] %s | %s | %s | %s | %s\n%s\n\n",
                $d['case_id'],
                $d['case_num']        ?? 'N/A',
                $date,
                $d['category']        ?? 'N/A',
                $d['dispute_subject'] ?? 'N/A',
                $d['result']          ?? 'N/A',
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
