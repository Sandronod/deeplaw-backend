<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LLM-as-Judge: o4-mini შეაფასებს GPT-4.1-ის პასუხს.
 *
 * გამოიყენება: finalize()-ში, synchronous.
 * შედეგი: meta['eval'] — JSON სტრუქტურა სკორებით და კომენტარებით.
 */
class EvalJudgeService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('openai.api_key');
        $this->model   = config('openai.judge_model', 'o4-mini');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    /**
     * @param  string  $userQuestion   მომხმარებლის კითხვა
     * @param  string  $answerText     GPT-4.1-ის პასუხი
     * @param  string  $mode           detect mode: explain|advise|find|chat|etc.
     * @param  array   $decisions      retrieved court decisions (case_num, excerpt, ...)
     * @param  array   $matsneResults  retrieved matsne docs (title, excerpt, ...)
     * @param  array   $echrResults    retrieved ECHR docs
     * @param  array   $euResults      retrieved EU docs
     * @param  array   $germanResults  retrieved German court docs
     * @param  array   $constCourtResults retrieved Constitutional Court docs
     * @return array   evaluation result with scores, verdict, issues, summary
     */
    public function evaluate(
        string $userQuestion,
        string $answerText,
        string $mode,
        array  $decisions = [],
        array  $matsneResults = [],
        array  $echrResults = [],
        array  $euResults = [],
        array  $germanResults = [],
        array  $constCourtResults = [],
    ): array {
        $prompt = $this->buildPrompt(
            $userQuestion,
            $answerText,
            $mode,
            $decisions,
            $matsneResults,
            $echrResults,
            $euResults,
            $germanResults,
            $constCourtResults,
        );

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(90)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'                 => $this->model,
                    'max_completion_tokens' => 4000,
                    'messages'              => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('EvalJudge: API error', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 300),
                ]);
                return $this->failResult('API error ' . $response->status());
            }

            $content = trim($response->json('choices.0.message.content') ?? '');

            Log::debug('EvalJudge: raw response', [
                'model'   => $this->model,
                'content' => mb_substr($content, 0, 500),
            ]);

            return $this->parseResult($content);

        } catch (\Throwable $e) {
            Log::warning('EvalJudge: exception — ' . $e->getMessage());
            return $this->failResult($e->getMessage());
        }
    }

    // ── Prompt ────────────────────────────────────────────────────────────────

    private function buildPrompt(
        string $question,
        string $answer,
        string $mode,
        array  $decisions,
        array  $matsneResults,
        array  $echrResults = [],
        array  $euResults = [],
        array  $germanResults = [],
        array  $constCourtResults = [],
    ): string {
        $sourcesBlock = $this->buildSourcesBlock(
            $decisions,
            $matsneResults,
            $echrResults,
            $euResults,
            $germanResults,
            $constCourtResults,
        );

        if ($sourcesBlock === '') {
            $sourcesBlock = "No sources were retrieved from the database.\n";
        }

        $modeNote = match ($mode) {
            'explain', 'advise' => 'IRAC structure (Issue→Rule→Application→Conclusion) is expected.',
            'advocate'          => 'Advocate structure with objective assessment + battle points is expected.',
            'find'              => 'List format with case details is expected. No deep analysis needed.',
            'summarize'         => 'Structured summary of decisions is expected.',
            'compare'           => 'Comparative analysis between decisions is expected.',
            'chat'              => 'Conversational reply. IRAC is NOT expected. Keep it natural.',
            default             => 'IRAC structure is expected for legal questions.',
        };

        $answerExcerpt = mb_substr($answer, 0, 12000);

        return <<<PROMPT
You are a senior Georgian legal expert and AI evaluator. Your task is to evaluate the quality of an AI-generated legal answer.

═══════════════════════════════════════
USER QUESTION:
{$question}

ANSWER MODE: {$mode}
MODE EXPECTATION: {$modeNote}

SOURCES AVAILABLE TO THE AI (retrieved from database):
{$sourcesBlock}

AI-GENERATED ANSWER:
{$answerExcerpt}
═══════════════════════════════════════

Evaluate the answer on these 5 dimensions (score 0-10 each):

1. **legal_accuracy** — Is the legal reasoning correct? Are cited legal principles and rules accurate under Georgian law?
2. **citation_validity** — Do all cited case numbers and laws appear in the SOURCES AVAILABLE list above? (10 = all citations valid/no hallucinated citations, 0 = fabricated citations)
3. **structure** — Is the answer structure appropriate for the mode? See MODE EXPECTATION above.
4. **completeness** — Does the answer address all aspects of the user question?
5. **no_hallucination** — Are all facts, case numbers, and legal rules grounded in the available sources or well-established Georgian law? (10 = no hallucination, 0 = fabricated facts)

Important evaluation rules:
- If an answer explicitly says "direct precedent was not found" and labels a retrieved case as analogy/supporting context, do not punish it as hallucination merely because it is not a direct precedent.
- Constitutional Court, ECHR, EU, and German sources listed below are valid retrieved sources for citation validity. Do not require them to appear in the domestic court-decision list.
- For legislation, article numbers are valid when the corresponding Matsne source/article is listed below.

Return ONLY valid JSON (no markdown, no explanation outside JSON):
{
  "scores": {
    "legal_accuracy": <0-10>,
    "citation_validity": <0-10>,
    "structure": <0-10>,
    "completeness": <0-10>,
    "no_hallucination": <0-10>
  },
  "overall": <0.0-10.0, weighted: legal_accuracy×0.3 + citation_validity×0.25 + no_hallucination×0.25 + completeness×0.15 + structure×0.05>,
  "verdict": "<excellent|good|acceptable|poor>",
  "issues": ["<specific problem if any>"],
  "strengths": ["<what was done well>"],
  "summary": "<2-3 sentence evaluation in Georgian>"
}
PROMPT;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $echrResults
     * @param array<int, array<string, mixed>> $euResults
     * @param array<int, array<string, mixed>> $germanResults
     * @param array<int, array<string, mixed>> $constCourtResults
     */
    private function buildSourcesBlock(
        array $decisions,
        array $matsneResults,
        array $echrResults,
        array $euResults,
        array $germanResults,
        array $constCourtResults,
    ): string {
        $lines = [];

        foreach ($decisions as $decision) {
            $caseNum = $decision['case_num'] ?? null;
            if (!$caseNum) {
                continue;
            }
            $role = $decision['answer_role'] ?? 'source';
            $lines[] = sprintf(
                '- Georgian court decision: %s | role=%s | date=%s | excerpt=%s',
                $caseNum,
                $role,
                $decision['case_date'] ?? 'N/A',
                $this->shortSourceText($decision['excerpt'] ?? $decision['full_text'] ?? $decision['content'] ?? ''),
            );
        }

        foreach ($matsneResults as $doc) {
            $article = $doc['_article_num'] ?? $doc['article_num'] ?? null;
            $articleText = $article ? " | article={$article}" : '';
            $lines[] = sprintf(
                '- Legislation/Matsne: %s%s | url=%s | excerpt=%s',
                $doc['title'] ?? 'N/A',
                $articleText,
                $doc['url'] ?? 'N/A',
                $this->shortSourceText($doc['excerpt'] ?? $doc['content'] ?? $doc['text'] ?? ''),
            );
        }

        foreach ($constCourtResults as $doc) {
            $lines[] = sprintf(
                '- Constitutional Court: %s | legal_id=%s | date=%s | url=%s | excerpt=%s',
                $doc['case_number'] ?? $doc['legal_id'] ?? 'N/A',
                $doc['legal_id'] ?? 'N/A',
                $doc['decision_date'] ?? 'N/A',
                $doc['url'] ?? 'N/A',
                $this->shortSourceText($doc['excerpt'] ?? $doc['summary'] ?? $doc['content'] ?? ''),
            );
        }

        foreach ($echrResults as $doc) {
            $lines[] = sprintf(
                '- ECHR: %s | application=%s | url=%s | excerpt=%s',
                $doc['title'] ?? $doc['case_name'] ?? 'N/A',
                $doc['application_no'] ?? $doc['application_number'] ?? 'N/A',
                $doc['url'] ?? 'N/A',
                $this->shortSourceText($doc['excerpt'] ?? $doc['summary'] ?? $doc['content'] ?? ''),
            );
        }

        foreach ($euResults as $doc) {
            $lines[] = sprintf(
                '- EU source: %s | citation=%s | url=%s | excerpt=%s',
                $doc['title'] ?? $doc['case_name'] ?? 'N/A',
                $doc['citation'] ?? $doc['celex'] ?? 'N/A',
                $doc['url'] ?? 'N/A',
                $this->shortSourceText($doc['excerpt'] ?? $doc['summary'] ?? $doc['content'] ?? ''),
            );
        }

        foreach ($germanResults as $doc) {
            $lines[] = sprintf(
                '- German court source: %s | citation=%s | url=%s | excerpt=%s',
                $doc['title'] ?? $doc['case_name'] ?? 'N/A',
                $doc['citation'] ?? $doc['case_number'] ?? 'N/A',
                $doc['url'] ?? 'N/A',
                $this->shortSourceText($doc['excerpt'] ?? $doc['summary'] ?? $doc['content'] ?? ''),
            );
        }

        return implode("\n", array_slice($lines, 0, 40));
    }

    private function shortSourceText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return mb_substr($text, 0, 700);
    }

    // ── Parser ────────────────────────────────────────────────────────────────

    private function parseResult(string $content): array
    {
        // Strip markdown code fences if present
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);

        // Extract first JSON object
        if (preg_match('/\{[\s\S]+\}/u', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['scores'])) {
                $decoded['evaluated_at'] = now()->toISOString();
                $decoded['model']        = $this->model;
                return $decoded;
            }
        }

        Log::warning('EvalJudge: could not parse JSON', ['content' => mb_substr($content, 0, 400)]);
        return $this->failResult('JSON parse failed');
    }

    private function failResult(string $reason): array
    {
        return [
            'scores'       => null,
            'overall'      => null,
            'verdict'      => 'eval_failed',
            'issues'       => [],
            'strengths'    => [],
            'summary'      => null,
            'error'        => $reason,
            'evaluated_at' => now()->toISOString(),
            'model'        => $this->model,
        ];
    }
}
