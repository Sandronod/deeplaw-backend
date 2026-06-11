<?php

namespace App\Services\AI;

use App\Services\Legal\LegalGlossaryService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueryExtractorService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(
        private readonly LegalGlossaryService $glossary,
        private readonly LegalQueryNormalizerService $normalizer,
    )
    {
        $this->apiKey  = config('openai.api_key');
        // Intentionally uses extraction_model (mini) — simple keyword task, gpt-4.1 is overkill
        $this->model   = config('openai.extraction_model', 'gpt-4.1-mini');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    private const VALID_DOMAINS = [
        'civil', 'criminal', 'admin', 'corporate',
        'labor', 'property', 'procedure', 'tax', 'family', 'echr',
    ];

    private const DOMAIN_DRIFT_SIGNALS = [
        'criminal' => ['სისხლის', 'დანაშაულ', 'სასჯელ', 'ბრალ', 'პატიმრ', 'მსჯავრდ', 'განაჩენ'],
        'family' => ['განქორწინ', 'მეუღლ', 'შვილ', 'ალიმენტ', 'მეურვ'],
        'labor' => ['შრომ', 'დასაქმ', 'გათავისუფლ', 'ხელფას', 'სამუშაო'],
        'tax' => ['საგადასახად', 'გადასახად', 'დღგ', 'საბაჟო'],
    ];

    /**
     * Extracts search terms AND legal domain from the user message in one LLM call.
     *
     * Returns ['query' => string, 'domain' => string|null]
     *
     * "დამქირავებელს შეუძლია კონტრაქტი შეწყვიტოს?"
     *   → ['query' => "ქირავნობა\nხელშეკრულების შეწყვეტა", 'domain' => 'civil']
     */
    public function extract(string $userMessage): string
    {
        return $this->extractWithDomain($userMessage)['query'];
    }

    public function extractWithDomain(string $userMessage): array
    {
        try {
            $response = Http::retry(3, 600, fn ($e) =>
                $e instanceof RequestException && in_array($e->response?->status(), [500, 502, 503, 529])
            )
                ->withToken($this->apiKey)
                ->timeout(15)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => 160,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => <<<'PROMPT'
შენ ქართული სამართლის ექსპერტი ხარ. მომხმარებლის შეტყობინებიდან ამოიღე:
1. საძიებო ტერმინები
2. სამართლის დარგი

დააბრუნე მხოლოდ JSON:
{
  "query": "ტერმინები — ერთი ხაზზე ერთი, მაქს 6, task-სიტყვები მოშორებული",
  "domain": "civil|criminal|admin|corporate|labor|property|procedure|tax|family|echr|null"
}

domain წესები:
- civil     → სამოქალაქო, ხელშეკრულება, კონტრაქტი, ქირავნობა, ნასყიდობა, ზიანი, ვალდებულება
- criminal  → სისხლის, დანაშაული, სასჯელი, ბრალი
- admin     → ადმინისტრაციული, ნებართვა, სამინისტრო, საჯარო
- corporate → შპს, სს, დირექტორი, პარტნიორი, სამეწარმეო
- labor     → შრომა, დასაქმება, ხელფასი, გათავისუფლება, სამუშაო
- property  → საკუთრება, მიწა, იპოთეკა, უძრავი ქონება
- tax       → საგადასახადო, დღგ, გადასახადი
- family    → განქორწინება, მეუღლე, შვილი, მეურვეობა, ალიმენტი
- procedure → სარჩელი, გასაჩივრება, მტკიცებულება, საპროცესო
- echr      → სტრასბურგი, ადამიანის უფლება, კონვენცია
- null      → გაურკვეველია

მაგალითები:
"დამქირავებელს შეუძლია კონტრაქტი შეწყვიტოს?" → {"query":"ქირავნობა\nხელშეკრულების ცალმხრივი შეწყვეტა","domain":"civil"}
"შრომითი დავა გათავისუფლებასთან დაკავშირებით" → {"query":"შრომითი დავა\nგათავისუფლება","domain":"labor"}
"ბავშვის მეურვეობა განქორწინებისას" → {"query":"მეურვეობა\nალიმენტი\nგანქორწინება","domain":"family"}
PROMPT,
                        ],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            $raw = trim($response->json('choices.0.message.content') ?? '');
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/i', '', $raw);

            $parsed = json_decode($raw, true);
            $query  = trim($parsed['query'] ?? '');
            $domain = $parsed['domain'] ?? null;

            if (empty($query)) {
                return $this->fallbackExtraction($userMessage);
            }

            // Validate domain
            if (!in_array($domain, self::VALID_DOMAINS, true)) {
                $domain = null;
            }

            if ($this->isClearlyUnrelatedExtraction($userMessage, $query, $domain)) {
                Log::warning('QueryExtractor: discarded unrelated extraction', [
                    'original' => $userMessage,
                    'query' => $query,
                    'domain' => $domain,
                ]);

                return $this->fallbackExtraction($userMessage);
            }

            // Expand with glossary synonyms
            $synonyms = $this->glossary->expandQuery($userMessage);
            if (!empty($synonyms)) {
                $query .= "\n" . implode("\n", $synonyms);
                Log::debug('QueryExtractor: glossary expansion', ['added' => $synonyms]);
            }

            $normalization = $this->normalizer->normalize($userMessage, $query);
            $query = $normalization['query'];

            Log::debug('QueryExtractor', [
                'original' => $userMessage,
                'query'    => $query,
                'domain'   => $domain,
                'normalization' => [
                    'changed' => $normalization['changed'],
                    'added_terms' => $normalization['added_terms'],
                    'rule_triggers' => $normalization['rule_triggers'],
                    'outcome_categories' => $normalization['outcome_categories'],
                ],
            ]);

            return ['query' => $query, 'domain' => $domain, 'normalization' => $normalization];

        } catch (\Throwable $e) {
            Log::warning('QueryExtractor failed, using original: ' . $e->getMessage());
        }

        return $this->fallbackExtraction($userMessage);
    }

    private function fallbackExtraction(string $userMessage): array
    {
        $normalization = $this->normalizer->normalize($userMessage, $userMessage);

        return [
            'query' => $normalization['query'],
            'domain' => null,
            'normalization' => $normalization,
        ];
    }

    private function isClearlyUnrelatedExtraction(string $original, string $query, ?string $domain): bool
    {
        $originalLower = mb_strtolower($original);
        $queryLower = mb_strtolower($query);
        $overlap = $this->meaningfulTokenOverlap($originalLower, $queryLower);

        foreach (self::DOMAIN_DRIFT_SIGNALS as $signalDomain => $signals) {
            $queryHasDomain = $this->containsAny($queryLower, $signals);

            if (!$queryHasDomain || $this->containsAny($originalLower, $signals)) {
                continue;
            }

            if ($domain === $signalDomain || $overlap === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts exact overlap of meaningful tokens. This is intentionally used only
     * as a guardrail around domain-drift signals, not as a general synonym judge.
     */
    private function meaningfulTokenOverlap(string $left, string $right): int
    {
        $leftTokens = $this->meaningfulTokens($left);
        $rightTokens = $this->meaningfulTokens($right);

        return count(array_intersect($leftTokens, $rightTokens));
    }

    /**
     * @return array<int, string>
     */
    private function meaningfulTokens(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
        $stopWords = ['და', 'ან', 'რომ', 'არის', 'იყო', 'თუ', 'რა', 'რას', 'როგორ', 'საკითხი'];

        return array_values(array_unique(array_filter(
            $tokens,
            fn (string $token) => mb_strlen($token) >= 4 && !in_array($token, $stopWords, true),
        )));
    }

    /**
     * @param array<int, string> $signals
     */
    private function containsAny(string $text, array $signals): bool
    {
        foreach ($signals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }
}
