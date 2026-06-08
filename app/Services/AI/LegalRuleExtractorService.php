<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Two-Stage Answering — Stage 1: Rule Extraction.
 *
 * Reads retrieved matsne article texts and extracts concrete, actionable rules
 * (deadlines, thresholds, procedural requirements) as structured data.
 *
 * This prevents GPT-4.1 from defaulting to training priors (e.g. "3 years for everything")
 * when a lex specialis rule exists in the retrieved articles.
 *
 * Output is injected into the answer prompt as a highlighted "📌 EXTRACTED RULES" block
 * BEFORE the full article texts, exploiting the primacy effect.
 */
class LegalRuleExtractorService
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
     * Extract concrete rules from matsne article texts.
     *
     * @param  string  $question      User's legal question (for context)
     * @param  array   $matsneResults Articles from ArticleDetector / ConceptDetector
     * @return array   [{article_num, code_name, rule, type, applies_when}, ...]
     */
    public function extract(string $question, array $matsneResults): array
    {
        if (empty($matsneResults)) {
            return [];
        }

        // Only process article-specific results (not semantic search results)
        $articleDocs = array_filter(
            $matsneResults,
            fn($r) => in_array($r['_source'] ?? '', ['article_detector', 'concept_detector'], true)
        );

        if (empty($articleDocs)) {
            return [];
        }

        $articlesBlock = $this->buildArticlesBlock($articleDocs);
        $prompt        = $this->buildPrompt($question, $articlesBlock);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(20)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => 800,
                    'messages'    => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('RuleExtractor: API error', ['status' => $response->status()]);
                return [];
            }

            $content = trim($response->json('choices.0.message.content') ?? '');
            $rules   = $this->parse($content);

            if (!empty($rules)) {
                Log::warning('RuleExtractor: extracted rules', [
                    'count' => count($rules),
                    'rules' => array_column($rules, 'rule'),
                ]);
            }

            return $rules;

        } catch (\Throwable $e) {
            Log::warning('RuleExtractor: exception — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Formats extracted rules as a highlighted block for the AI answer prompt.
     * Placed BEFORE full article texts so primacy effect kicks in.
     */
    public function buildPromptBlock(array $rules): string
    {
        if (empty($rules)) {
            return '';
        }

        $lines = [
            "╔══════════════════════════════════════════════════╗",
            "║  📌 EXTRACTED RULES FROM RETRIEVED LEGISLATION   ║",
            "║  (გამოიყენე ეს წესები — არა training knowledge)  ║",
            "╚══════════════════════════════════════════════════╝",
            "",
            "⚠️ ქვემოთ მოცემული წესები ამოღებულია CONTEXT-ის კანონებიდან.",
            "⚠️ ეს წესები OVERRIDE-ს უკეთებს ნებისმიერ ზოგად ცოდნას.",
            "",
        ];

        foreach ($rules as $r) {
            $articleRef = $r['article_num'] ? "მუხლი {$r['article_num']}" : '';
            $codeName   = $r['code_name'] ?? '';
            $ref        = implode(' — ', array_filter([$codeName, $articleRef]));
            $type       = match ($r['type'] ?? '') {
                'deadline'    => '⏱ ვადა',
                'threshold'   => '💰 ზღვარი',
                'requirement' => '📋 მოთხოვნა',
                'procedure'   => '⚙️ პროცედურა',
                'consequence' => '⚖️ შედეგი',
                default       => '📌 წესი',
            };

            $appliesWhen = !empty($r['applies_when']) ? " [{$r['applies_when']}]" : '';
            $lines[]     = "• {$type}: **{$r['rule']}**{$appliesWhen}";
            if ($ref) {
                $lines[] = "  წყარო: {$ref}";
            }
            $lines[] = '';
        }

        $lines[] = "════════════════════════════════════════════════════";

        return implode("\n", $lines);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function buildArticlesBlock(array $docs): string
    {
        $parts = [];
        foreach ($docs as $doc) {
            $artNum  = $doc['_article_num'] ?? '?';
            $title   = $doc['title'] ?? '';
            $excerpt = mb_substr($doc['excerpt'] ?? '', 0, 600);
            $parts[] = "=== {$title} — მუხლი {$artNum} ===\n{$excerpt}";
        }
        return implode("\n\n", $parts);
    }

    private function buildPrompt(string $question, string $articlesBlock): string
    {
        return <<<PROMPT
შენ ხარ Georgian legal rule extractor. წაიკითხე ქვემოთ მოცემული სამართლებრივი მუხლების ტექსტი და ამოიღე ყველა კონკრეტული წესი.

მომხმარებლის კითხვა (კონტექსტისთვის): {$question}

სამართლებრივი მუხლები:
{$articlesBlock}

ამოიღე ყველა კონკრეტული:
- ვადა (deadline): "X დღე", "X თვე", "X წელი" + როდიდან ითვლება
- ზღვარი (threshold): თანხა, ოდენობა, პროცენტი
- მოთხოვნა (requirement): ვალდებულება, შეზღუდვა
- შედეგი (consequence): ბათილობა, პასუხისმგებლობა

დაბრუნე მხოლოდ JSON array. სხვა ტექსტი არ დაამატო:
[
  {
    "article_num": 449,
    "code_name": "სამოქ. კოდ.",
    "rule": "კონკრეტული წესი ქართულად — ზუსტი ციტატა ან მოკლე გადმოცემა",
    "type": "deadline|threshold|requirement|procedure|consequence",
    "applies_when": "როდის ვრცელდება (მოკლედ)"
  }
]

თუ ტექსტში კონკრეტული ციფრი/ვადა/ზღვარი არ არის → იმ მუხლზე ჩანაწერი არ გააკეთო.
PROMPT;
    }

    private function parse(string $content): array
    {
        // Strip markdown fences
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);

        if (preg_match('/\[.*\]/s', $content, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) {
                return array_values(array_filter($data, fn($r) =>
                    !empty($r['rule']) && mb_strlen($r['rule']) > 5
                ));
            }
        }

        Log::warning('RuleExtractor: JSON parse failed', ['content' => mb_substr($content, 0, 200)]);
        return [];
    }
}
