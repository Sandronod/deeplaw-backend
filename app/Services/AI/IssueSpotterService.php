<?php

namespace App\Services\AI;

use App\DTOs\IssueList;
use App\DTOs\LegalIssue;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GPT-4.1-mini ამოყოფს ყველა სამართლებრივ საკითხს კაზუსიდან.
 *
 * გაეშვება მხოლოდ:
 *  - mode = 'advise' ან 'advocate'
 *  - ან ტექსტი > 150 სიმბოლო
 */
class IssueSpotterService
{
    private const VALID_DOMAINS = [
        'civil', 'criminal', 'admin', 'corporate',
        'labor', 'property', 'procedure', 'tax', 'family', 'echr',
    ];

    private const FEW_SHOT = <<<'FEWSHOT'
SAMPLE INPUT:
"კომპანია ა-მ ნინოსთან ნასყიდობის ხელშეკრულება გააფორმა. კომპანიის დირექტორმა მოაწერა ხელი, მაგრამ წესდებით მას ასეთი უფლება არ ჰქონდა. ნინომ ბე გადაიხადა 15,000 ლარი. ახლა კომპანია ამბობს ხელშეკრ. ბათილია. ნინო ითხოვს ბეს + 50,000 ლ. ზიანს."

SAMPLE OUTPUT:
[
  {"title": "ხელშეკრულების ნამდვილობა — დირექტორის წარმომადგენლობის ფარგლები", "domain": "corporate", "priority": 1, "keywords": ["დირექტორი", "წარმომადგენლობა", "ბათილობა", "წესდება"]},
  {"title": "ნასყიდობის ხელშეკრულების ფორმის მოთხოვნები", "domain": "civil", "priority": 2, "keywords": ["ნასყიდობა", "ფორმა", "რეგისტრაცია"]},
  {"title": "ბეს სამართლებრივი ბედი ბათილობისას", "domain": "civil", "priority": 3, "keywords": ["ბე", "დაბრუნება", "ბათილობა"]},
  {"title": "კომპანიის პასუხისმგებლობა დირექტორის ქმედებაზე", "domain": "corporate", "priority": 4, "keywords": ["კომპანია", "პასუხისმგებლობა", "მესამე პირი"]},
  {"title": "ზიანის ანაზღაურება ბათილი გარიგებისას", "domain": "civil", "priority": 5, "keywords": ["ზიანი", "ანაზღაურება", "50000"]},
  {"title": "მოთხოვნის ხანდაზმულობა", "domain": "procedure", "priority": 6, "keywords": ["ხანდაზმულობა", "ვადა", "მოთხოვნა"]}
]
FEWSHOT;

    public function __construct()
    {
        $this->apiKey  = config('openai.api_key');
        $this->model   = config('openai.extraction_model', 'gpt-4.1-mini');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    private string $apiKey;
    private string $model;
    private string $baseUrl;

    /**
     * ამოყოფს სამართლებრივ საკითხებს.
     * შეცდომისას აბრუნებს IssueList::empty().
     */
    public function spot(string $userQuestion): IssueList
    {
        try {
            $response = Http::retry(2, 400, fn($e) =>
                $e instanceof RequestException
                && in_array($e->response?->status(), [500, 502, 503, 529])
            )
                ->withToken($this->apiKey)
                ->timeout(15)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => 1000,
                    'messages'    => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user',   'content' => self::FEW_SHOT . "\n\nINPUT:\n\"{$userQuestion}\"\n\nOUTPUT:"],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('IssueSpotter: API error', ['status' => $response->status()]);
                return IssueList::empty();
            }

            $content = trim($response->json('choices.0.message.content') ?? '');
            $issues  = $this->parse($content);

            Log::debug('IssueSpotter: spotted', [
                'count'   => count($issues),
                'domains' => array_unique(array_column(array_map(fn($i) => $i->toArray(), $issues), 'domain')),
            ]);

            return new IssueList(
                issues:     $issues,
                issueCount: count($issues),
                isComplex:  count($issues) > 2,
            );

        } catch (\Throwable $e) {
            Log::warning('IssueSpotter: exception — ' . $e->getMessage());
            return IssueList::empty();
        }
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
შენ ხარ Georgian legal issues extractor.

ამოცანა: წაიკითხე კაზუსი/შეკითხვა და გამოყავი ყველა სამართლებრივი საკითხი.

RULES:
- ამოიყვანე ყველა საკითხი (მინ. 1, მაქს. 8)
- თითოეული = ერთი კონკრეტული სამართლებრივი კითხვა
- priority: 1 = ყველაზე კრიტიკული
- domain: მხოლოდ ეს მნიშვნელობები: civil, criminal, admin, corporate, labor, property, procedure, tax, family, echr
- keywords: 2-4 ქართული სიტყვა retrieval-ისთვის

OUTPUT: მხოლოდ JSON array. სხვა ტექსტი არ დაამატო.
PROMPT;
    }

    /**
     * @return LegalIssue[]
     */
    private function parse(string $content): array
    {
        // JSON block-ის ამოღება (```json ... ``` შეიძლება ჰქონდეს)
        if (preg_match('/\[.*\]/s', $content, $m)) {
            $content = $m[0];
        }

        $data = json_decode($content, true);

        if (!is_array($data) || empty($data)) {
            Log::warning('IssueSpotter: invalid JSON', ['content' => mb_substr($content, 0, 200)]);
            return [];
        }

        $issues = [];
        foreach ($data as $i => $item) {
            if (!isset($item['title'], $item['domain'], $item['priority'])) {
                continue;
            }

            $domain = in_array($item['domain'], self::VALID_DOMAINS)
                ? $item['domain']
                : 'civil'; // fallback

            $issues[] = new LegalIssue(
                title:    trim((string) $item['title']),
                domain:   $domain,
                priority: max(1, (int) $item['priority']),
                keywords: array_values(array_filter(
                    array_map('trim', (array) ($item['keywords'] ?? []))
                )),
            );
        }

        // priority-ით სორტირება
        usort($issues, fn($a, $b) => $a->priority <=> $b->priority);

        return $issues;
    }
}
