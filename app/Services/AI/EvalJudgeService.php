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
     * @return array   evaluation result with scores, verdict, issues, summary
     */
    public function evaluate(
        string $userQuestion,
        string $answerText,
        string $mode,
        array  $decisions = [],
        array  $matsneResults = [],
    ): array {
        $prompt = $this->buildPrompt($userQuestion, $answerText, $mode, $decisions, $matsneResults);

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
    ): string {
        // Build source whitelist for citation check
        $caseNums = collect($decisions)
            ->pluck('case_num')
            ->filter()
            ->values()
            ->implode(', ');

        $matsneTitles = collect($matsneResults)
            ->pluck('title')
            ->filter()
            ->map(fn($t) => mb_substr($t, 0, 80))
            ->values()
            ->implode('; ');

        $sourcesBlock = '';
        if ($caseNums) {
            $sourcesBlock .= "Court decisions available: {$caseNums}\n";
        }
        if ($matsneTitles) {
            $sourcesBlock .= "Legislation available: {$matsneTitles}\n";
        }
        if (!$sourcesBlock) {
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

        $answerExcerpt = mb_substr($answer, 0, 3000);

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
