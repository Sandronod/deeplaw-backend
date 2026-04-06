<?php

namespace App\Services\AI;

use App\Contracts\AnswerServiceInterface;
use App\DTOs\ConfidenceResult;
use App\DTOs\LawResult;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiLegalAnswerService implements AnswerServiceInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int    $timeout;
    private int    $maxTokens;
    private float  $temperature;

    public function __construct()
    {
        $this->apiKey      = config('ai.gemini.api_key');
        $this->model       = config('ai.gemini.chat_model', 'gemini-1.5-flash');
        $this->baseUrl     = config('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1');
        $this->timeout     = config('ai.gemini.timeout', 60);
        $this->maxTokens   = config('ai.gemini.max_tokens', 2048);
        $this->temperature = config('ai.gemini.temperature', 0.2);
    }

    // ── Non-streaming answer ──────────────────────────────────────────────────

    public function answer(
        string           $userQuestion,
        array            $decisions,
        array            $historyMessages = [],
        int              $totalFound = 0,
        string           $mode = 'explain',
        ConfidenceResult $confidence = new ConfidenceResult(0.0, 'none', ''),
        array            $lawResults = [],
        array            $echrResults = [],
    ): string {
        [$text] = $this->callGemini($userQuestion, $decisions, $historyMessages, $totalFound, $mode, $confidence, $lawResults);
        return $text;
    }

    // ── Streaming ─────────────────────────────────────────────────────────────

    public function streamTokens(
        string           $userQuestion,
        array            $decisions,
        array            $historyMessages = [],
        int              $totalFound = 0,
        string           $mode = 'explain',
        ConfidenceResult $confidence = new ConfidenceResult(0.0, 'none', ''),
        array            $lawResults = [],
        array            $echrResults = [],
    ): \Generator {
        [$text] = $this->callGemini($userQuestion, $decisions, $historyMessages, $totalFound, $mode, $confidence, $lawResults);

        // Simulate streaming: yield word by word
        $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($words as $word) {
            if ($word !== '') {
                yield $word;
            }
        }
    }

    // ── Core API call ─────────────────────────────────────────────────────────

    private function callGemini(
        string           $userQuestion,
        array            $decisions,
        array            $historyMessages,
        int              $totalFound,
        string           $mode,
        ConfidenceResult $confidence,
        array            $lawResults,
    ): array {
        $systemPrompt = $this->buildSystemPrompt($mode, $confidence);
        $contextBlock = $this->buildContextBlock($decisions, $totalFound, $mode, $lawResults);
        $contents     = $this->buildContents($contextBlock, $historyMessages, $userQuestion);

        $url    = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";
        $client = new GuzzleClient();

        $response = $client->post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents'           => $contents,
                'generationConfig'   => [
                    'maxOutputTokens' => $this->maxTokens,
                    'temperature'     => $this->temperature,
                ],
            ],
            'timeout' => $this->timeout,
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($text)) {
            throw new RuntimeException('Gemini returned empty response');
        }

        Log::info('GeminiLegalAnswerService: called', [
            'model'  => $this->model,
            'mode'   => $mode,
            'tokens' => $data['usageMetadata']['totalTokenCount'] ?? null,
        ]);

        return [trim($text), $data];
    }

    // ── Message Assembly ──────────────────────────────────────────────────────

    private function buildContents(string $contextBlock, array $historyMessages, string $userQuestion): array
    {
        $contents = [];

        // History (Gemini uses "model" instead of "assistant")
        foreach ($historyMessages as $msg) {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => mb_substr($msg['content'], 0, 3000)]],
            ];
        }

        // Current turn: context block + user question
        $userTurn = empty($contextBlock)
            ? $userQuestion
            : "CONTEXT:\n\n{$contextBlock}\n\n---\n\nKITXVA: {$userQuestion}";

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $userTurn]],
        ];

        return $contents;
    }

    // ── System Prompt (same as OpenAI) ────────────────────────────────────────

    private function buildSystemPrompt(string $mode, ConfidenceResult $confidence): string
    {
        $modeInstruction   = $this->buildModeInstruction($mode);
        $confidenceSection = $this->buildConfidenceInstruction($confidence);

        return <<<PROMPT
შენ ხარ პროფესიონალი იურიდიული ასისტენტი (Legal Copilot), რომელიც ეხმარება იურისტებს სასამართლო პრაქტიკის ანალიზში.

შენი მთავარი ამოცანაა:
- უპასუხო შეკითხვებს სამართლებრივად სწორად
- დაეყრდნო პირველ რიგში მოწოდებულ სასამართლო გადაწყვეტილებებს (CONTEXT)
- არ მოიგონო ფაქტები, საქმეები ან სამართლებრივი დეტალები

────────────────────────
📚 ცოდნის პრიორიტეტი (STRICT)
────────────────────────

1. RETRIEVED LEGISLATION (მოწოდებული კანონები/მუხლები) — თუ არსებობს
2. RETRIEVED COURT DECISIONS (სასამართლო გადაწყვეტილებები) — თუ არსებობს
3. ზოგადი სამართლებრივი ცოდნა
4. თუ არც ერთი არ არის საკმარისი → თქვი რომ ინფორმაცია არასაკმარისია

❗ აკრძალულია:
- არარსებული საქმეების გამოგონება
- case number-ის გამოგონება
- სასამართლოს დასკვნის შეცვლა
- CONTEXT-ში არარსებული ფაქტების დამატება

────────────────────────
🔍 პასუხის ფორმატი (MANDATORY)
────────────────────────

ყოველ პასუხში დაიცავი ეს სტრუქტურა:

1. 🧾 გამოყენებული საქმეები (Evidence)
2. 📌 ამონარიდი (Facts Extraction)
3. ⚖️ სამართლებრივი ანალიზი (Reasoning)
4. 🧠 დასკვნა (Conclusion)
5. 📊 სანდოობა (Confidence)

────────────────────────
📊 CONFIDENCE RULES
────────────────────────

- მაღალი → პირდაპირი შესაბამისობა, რამდენიმე საქმე
- საშუალო → ნაწილობრივი შესაბამისობა
- დაბალი → სუსტი კავშირი ან არასაკმარისი მონაცემი

{$confidenceSection}

────────────────────────
📌 ACTIVE MODE: {$mode}
────────────────────────

{$modeInstruction}

────────────────────────
🚫 STRICT RULES
────────────────────────

- არ მოიგონო არაფერი რაც CONTEXT-ში არ არის
- ქართულ კითხვაზე → ქართულად; ინგლისურ კითხვაზე → ინგლისურად
- follow-up კითხვებში გამოიყენე წინა კონტექსტი
PROMPT;
    }

    private function buildModeInstruction(string $mode): string
    {
        return match ($mode) {
            'find'      => 'მომხმარებელს სურს გადაწყვეტილებების მოძებნა. მიეცი სია case_num, თარიღი, შედეგი.',
            'summarize' => 'მომხმარებელს სურს შეჯამება. გამოიყენე: ფაქტები → სამართლებრივი საკითხი → გადაწყვეტილება.',
            'compare'   => 'მომხმარებელს სურს შედარება. შეადარე ფაქტები, მსჯელობა, შედეგი.',
            'explain'   => 'გამოიყენე IRAC: საკითხი → ნორმა → გამოყენება → დასკვნა.',
            'advise'    => 'პრაქტიკული ორიენტაცია. ბოლოში: "მიმართეთ კვალიფიციურ იურისტს."',
            default     => 'გამოიყენე ყველაზე შესაფერისი სტრუქტურა.',
        };
    }

    private function buildConfidenceInstruction(ConfidenceResult $confidence): string
    {
        return match ($confidence->label) {
            'high'   => "RETRIEVAL: მაღალი სანდოობა [{$confidence->explanation}]. გამოიყენე ბაზა.",
            'medium' => "RETRIEVAL: საშუალო სანდოობა [{$confidence->explanation}]. მიუთითე გაურკვევლობა.",
            'low'    => "RETRIEVAL: დაბალი სანდოობა [{$confidence->explanation}]. ⚠️ განასხვავე ბაზა vs ზოგადი ცოდნა.",
            'none'   => "RETRIEVAL: NO_RESULTS. პასუხი ეფუძნება ზოგად სამართლებრივ ცოდნას — მიუთითე ეს.",
            default  => '',
        };
    }

    private function buildContextBlock(array $decisions, int $totalFound, string $mode, array $lawResults): string
    {
        $parts = [];

        if (!empty($lawResults)) {
            $lawBlock = ["RETRIEVED LEGISLATION:\n"];
            foreach ($lawResults as $i => $law) {
                /** @var LawResult $law */
                $num          = $i + 1;
                $articleLabel = $law->articleNum
                    ? "{$law->articleNum}" . ($law->articleTitle ? " — {$law->articleTitle}" : '')
                    : '';
                $lawBlock[] = "--- LAW #{$num} ---\nLaw: {$law->title}\n{$articleLabel}\nURL: {$law->sourceUrl}\n\nTEXT:\n{$law->excerpt}\n--- END LAW #{$num} ---";
            }
            $parts[] = implode("\n\n", $lawBlock);
        }

        if (empty($decisions)) {
            $parts[] = "STATUS: NO_RESULTS\nმონაცემთა ბაზაში შესაბამისი გადაწყვეტილება ვერ მოიძებნა.";
            return implode("\n\n────────────────────────\n\n", $parts);
        }

        $count  = count($decisions);
        $blocks = ["STATUS: FOUND — {$count} გადაწყვეტილება\n"];

        foreach ($decisions as $i => $d) {
            $num     = $i + 1;
            $caseUrl = "https://www.supremecourt.ge/ka/fullcase/{$d['case_id']}/0";
            $excerpt = $d['excerpt']   ?? '';
            $full    = $d['full_text'] ?? '';
            $limit   = 4000;
            $text    = mb_substr(!empty($excerpt) ? $excerpt : $full, 0, $limit);

            $blocks[] = "--- DECISION #{$num} ---\nCase: {$d['case_num']} | URL: {$caseUrl}\nTEXT:\n{$text}\n--- END DECISION #{$num} ---";
        }

        $parts[] = "RETRIEVED COURT DECISIONS:\n\n" . implode("\n\n", $blocks);

        return implode("\n\n────────────────────────\n\n", $parts);
    }
}
