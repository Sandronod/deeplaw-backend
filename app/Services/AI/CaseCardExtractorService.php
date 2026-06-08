<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extracts a structured "case card" from raw court decision text using GPT-4.1-mini.
 *
 * Output shape:
 *   legal_issue     — short description of the legal question decided (Georgian)
 *   applied_articles — array of cited articles, e.g. ["სკ 128", "სკ 394"]
 *   holding         — one-sentence court conclusion (Georgian)
 *   outcome         — "upheld" | "dismissed" | "partial" | "remanded" | "unclear"
 */
class CaseCardExtractorService
{
    private const MODEL      = 'gpt-4.1-mini';
    private const MAX_TOKENS = 1500;
    private const TEMP       = 0.0;

    private const SYSTEM_PROMPT = <<<'PROMPT'
შენ ხარ სამართლებრივი ექსტრაქტორი. გადაწყვეტილების ტექსტიდან ამოიყვანე JSON და მხოლოდ JSON დააბრუნე.

JSON სქემა (ყველა ველი სავალდებულოა):
{
  "legal_issue": "სამართლებრივი საკითხი — მოკლედ, მაქს 80 სიტყვა",
  "applied_articles": ["სკ 128", "სსსკ 394"],
  "holding": "სასამართლოს დასკვნა — 1 მოკლე წინადადება, მაქს 40 სიტყვა",
  "outcome": "upheld|dismissed|partial|remanded|unclear"
}

წესები:
- legal_issue: ძირითადი სამართლებრივი კითხვა, მაქს 80 სიტყვა (ხანდაზმულობა? ვალდებულება? ბათილობა? ...)
- applied_articles: მხოლოდ მოკლე კოდი + ნომერი, მაგ: "სკ 128", "სსსკ 394". სრული სახელები არ გამოიყენო. მაქს 8 item. თუ არ არის — []
- holding: საბოლოო დასკვნა, მაქს 40 სიტყვა
- outcome: upheld=დაკმაყოფილდა, dismissed=უარყოფილი, partial=ნაწილობრივ, remanded=დაბრუნდა, unclear=გაუგებარია
- დააბრუნე მხოლოდ JSON — არა markdown, არა კომენტარი
PROMPT;

    public function __construct(
        private readonly string $apiKey   = '',
        private readonly string $baseUrl  = '',
    ) {}

    /**
     * Extract a case card from the full text of a court decision.
     *
     * @param  string $caseText  Concatenated chunks of the decision
     * @return array|null        Decoded JSON array, or null on failure
     */
    public function extract(string $caseText): ?array
    {
        $apiKey  = $this->apiKey  ?: config('openai.api_key');
        $baseUrl = $this->baseUrl ?: config('openai.base_url', 'https://api.openai.com/v1');

        // Legal reasoning (with article citations) is typically in the middle/end.
        // Take first 500 chars (context) + last 2000 chars (reasoning + holding).
        // Keeps token count low while capturing article citations.
        $first = mb_substr($caseText, 0, 500);
        $last  = mb_substr($caseText, -2000);
        $text  = $first . "\n\n[...]\n\n" . $last;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post("{$baseUrl}/chat/completions", [
                    'model'       => self::MODEL,
                    'messages'    => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user',   'content' => $text],
                    ],
                    'max_tokens'  => self::MAX_TOKENS,
                    'temperature' => self::TEMP,
                ]);

            if ($response->failed()) {
                Log::warning('CaseCardExtractor: API error', ['status' => $response->status()]);
                return null;
            }

            $finishReason = $response->json('choices.0.finish_reason', '');
            $raw          = trim($response->json('choices.0.message.content', ''));

            // Strip markdown code fences if present
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/i', '', $raw);

            $card = json_decode($raw, true);

            if (!is_array($card) || empty($card['legal_issue'])) {
                $reason = $finishReason === 'length' ? 'truncated (hit max_tokens)' : 'invalid JSON';
                Log::warning("CaseCardExtractor: {$reason}", ['raw' => mb_substr($raw, 0, 200)]);
                return null;
            }

            return $this->normalise($card, $caseText);

        } catch (\Throwable $e) {
            Log::warning('CaseCardExtractor: exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function normalise(array $card, string $fullText = ''): array
    {
        $validOutcomes = ['upheld', 'dismissed', 'partial', 'remanded', 'unclear'];

        $gptArticles = array_values(array_filter(
            (array) ($card['applied_articles'] ?? []),
            fn($a) => is_string($a) && mb_strlen(trim($a)) > 0
        ));

        // Always scan full text with regex — merges with GPT results.
        // GPT sees only truncated text, so articles in the middle section are missed.
        // Regex covers the full document at no extra cost.
        $regexArticles = !empty($fullText) ? $this->extractArticlesRegex($fullText) : [];
        $articles      = array_values(array_unique(array_merge($gptArticles, $regexArticles)));

        return [
            'legal_issue'      => mb_substr((string) ($card['legal_issue'] ?? ''), 0, 500),
            'applied_articles' => $articles,
            'holding'          => mb_substr((string) ($card['holding'] ?? ''), 0, 500),
            'outcome'          => in_array($card['outcome'] ?? '', $validOutcomes, true)
                ? $card['outcome']
                : 'unclear',
        ];
    }

    /**
     * Regex-based article extractor for common Georgian citation formats:
     *   სკ 128, სსკ 412, სსსკ 84, სკ-ის 128-ე მუხლი, მუხლი 81 ...
     */
    private function extractArticlesRegex(string $text): array
    {
        $found = [];

        // Patterns: "სკ 128", "სსკ-ის 412", "სსსკ 84", "შრ.კ. 37" etc.
        $patterns = [
            '/\b(სკ|სსკ|სსსკ|ზაკ|შრ\.?კ\.?|ადმ\.?კ\.?|სახ\.?საკ\.?)[-\s–]*(\d+)/u',
            // "128-ე მუხლი" or "128-ე მუხლის"
            '/\b(\d{1,4})-?(?:ე|ელ|ლ)?\s+მუხლ/u',
            // "მუხლი 128" or "მუხლით 128"
            '/მუხლ[იით]+\s+(\d{1,4})/u',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                if (isset($m[2])) {
                    // "სკ 128" style
                    $code = mb_strtoupper(preg_replace('/[^a-zA-Zა-ჰ]/u', '', $m[1]));
                    $num  = $m[2];
                    $found[] = "{$code} {$num}";
                } else {
                    // article number only — store as-is
                    $found[] = 'მუხლი ' . $m[1];
                }
            }
        }

        // Deduplicate, limit to 10
        $found = array_values(array_unique($found));
        return array_slice($found, 0, 10);
    }
}
