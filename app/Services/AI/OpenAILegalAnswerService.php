<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAILegalAnswerService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int    $timeout;
    private int    $maxTokens;
    private float  $temperature;

    public function __construct()
    {
        $this->apiKey      = config('openai.api_key');
        $this->model       = config('openai.chat_model', 'gpt-4.1');
        $this->baseUrl     = config('openai.base_url', 'https://api.openai.com/v1');
        $this->timeout     = config('openai.timeout', 60);
        $this->maxTokens   = config('openai.max_tokens', 2048);
        $this->temperature = config('openai.temperature', 0.2);
    }

    /**
     * Sends a grounded legal query to OpenAI and returns the answer text.
     *
     * @param  string  $userQuestion
     * @param  array   $decisions       Reconstructed decisions from retriever
     * @param  array   $historyMessages [{role, content}, ...]
     * @return string
     *
     * @throws RuntimeException
     */
    public function answer(string $userQuestion, array $decisions, array $historyMessages = [], int $totalFound = 0): string
    {
        $systemPrompt = $this->buildSystemPrompt();
        $contextBlock = $this->buildContextBlock($decisions, $totalFound);
        $messages     = $this->buildMessages($systemPrompt, $contextBlock, $historyMessages, $userQuestion);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'messages'    => $messages,
                    'max_tokens'  => $this->maxTokens,
                    'temperature' => $this->temperature,
                ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'OpenAI Chat API error: ' . $response->status() . ' — ' . $response->body()
                );
            }

            $data = $response->json();

            if (empty($data['choices'][0]['message']['content'])) {
                throw new RuntimeException('OpenAI returned empty response.');
            }

            return trim($data['choices'][0]['message']['content']);

        } catch (RequestException $e) {
            throw new RuntimeException('OpenAI Chat request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
შენ ხარ უნივერსალური სამართლებრივი AI ასისტენტი, რომელიც ემსახურება ქართულ იურიდიულ სივრცეს.

შენ გაქვს ორი ცოდნის წყარო — გამოიყენე ორივე:
① მოძიებული გადაწყვეტილებები (context-ში გადაცემული) — კონკრეტული საქმეები, case number-ები, ფაქტები
② შენი საკუთარი ცოდნა — ქართული კანონმდებლობა, სამოქალაქო/სისხლის/ადმინისტრაციული კოდექსები,
   საერთაშორისო სამართლებრივი სტანდარტები, იურიდიული პრინციპები, პრაქტიკული რჩევები

══════════════════════════════════════════
ᲬᲧᲐᲠᲝᲔᲑᲘᲡ მარკირება — სავალდებულო
══════════════════════════════════════════

ყოველთვის მიუთითე საიდან მომდინარეობს ინფორმაცია:

📋 **ბაზიდან მოძიებული:** — კონკრეტული გადაწყვეტილებიდან
💡 **ზოგადი სამართლებრივი ცოდნა:** — კანონმდებლობა, პრინციპები, AI ცოდნა

══════════════════════════════════════════
INTENT DETECTION — მომხმარებლის განზრახვა
══════════════════════════════════════════

• "მიპოვე / find / ნახე" → metadata + მოკლე summary + ლინკი
• მოსამართლე/მხარე/ობიექტი (4+ გადაწყვეტილება) → სია: №, case_num, თარიღი, კატეგორია, შედეგი, ლინკი
• "შემიჯამე" → დეტალური შეჯამება: ფაქტები, მსჯელობა, შედეგი
• "განმარტე" → სამართლებრივი განმარტება — გადაწყვეტილება + კანონმდებლობა კომბინირებულად
• "შეადარე" → სტრუქტურირებული შედარება
• "რჩევა / რა უნდა ვქნა" → პრაქტიკული სამართლებრივი მითითება (⚠️ არ არის ადვოკატი)
• ზოგადი სამართლებრივი კითხვა → გადაწყვეტილება + კანონი + პრინციპები კომბინირებულად

══════════════════════════════════════════
ᲚᲘᲜᲙᲔᲑᲘ — სავალდებულო
══════════════════════════════════════════

გადაწყვეტილების ხსენებისას:
🔗 [გადაწყვეტილების სრული ტექსტი](https://www.supremecourt.ge/ka/fullcase/{CASE_ID}/0)

══════════════════════════════════════════
ᲬᲔᲡᲔᲑᲘ
══════════════════════════════════════════

1. ✅ STATUS: FOUND → გადაწყვეტილებები მოიძებნა — გამოიყენე + შეავსე საკუთარი ცოდნით.
2. ⚠️ STATUS: NO_RESULTS → ბაზაში ვერ მოიძებნა — პასუხი მხოლოდ საკუთარი ცოდნით, მაგრამ
   მიუთითე: "ბაზაში ეს კონკრეტული საქმე ვერ მოიძებნა, თუმცა ზოგადად..."
3. არასოდეს გამოიგონო case number-ები, თარიღები, კონკრეტული ფაქტები.
4. ზოგადი ცოდნის გამოყენებისას დისკლეიმერი: "⚠️ ეს არ არის იურიდიული კონსულტაცია."
5. მომხმარებლის ენაზე უპასუხე (ქართული → ქართული, English → English).
6. Follow-up კითხვებისთვის გამოიყენე წინა კონტექსტი.
7. პასუხი სტრუქტურირებული — პარაგრაფები, ნუმერაცია საჭიროებისამებრ.
PROMPT;
    }

    private function buildContextBlock(array $decisions, int $totalFound = 0): string
    {
        if (empty($decisions)) {
            return "⚠️ STATUS: NO_RESULTS — მონაცემთა ბაზაში ვერ მოიძებნა შესაბამისი გადაწყვეტილება. მომხმარებელს ამის შესახებ აცნობე.";
        }

        $count      = count($decisions);
        $totalNote  = ($totalFound > $count)
            ? " (მონაცემთა ბაზაში სულ {$totalFound} — ნაჩვენებია პირველი {$count})"
            : "";
        $blocks = ["✅ STATUS: FOUND — მოიძებნა {$count} გადაწყვეტილება{$totalNote}. გამოიყენე ეს გადაწყვეტილებები პასუხის გასაცემად. სულ ნაპოვნი: {$totalFound}. არ თქვა 'ვერ მოიძებნა'.\n"];

        foreach ($decisions as $i => $d) {
            $num = $i + 1;
            $date = $d['case_date'] instanceof \Carbon\Carbon
                ? $d['case_date']->format('Y-m-d')
                : ($d['case_date'] ?? 'N/A');

            $caseUrl = "https://www.supremecourt.ge/ka/fullcase/{$d['case_id']}/0";

            $meta = implode(' | ', array_filter([
                $d['case_num']        ? "Case: {$d['case_num']}"           : null,
                $d['case_date']       ? "Date: {$date}"                    : null,
                $d['court']           ? "Court: {$d['court']}"             : null,
                $d['chamber']         ? "Chamber: {$d['chamber']}"         : null,
                $d['category']        ? "Category: {$d['category']}"       : null,
                $d['claim_type']      ? "Claim: {$d['claim_type']}"        : null,
                $d['dispute_subject'] ? "Subject: {$d['dispute_subject']}" : null,
                $d['result']          ? "Result: {$d['result']}"           : null,
                "case_id: {$d['case_id']}",
                "URL: {$caseUrl}",
            ]));

            // Compact mode: >3 გადაწყვეტილება (მოსამართლე/ობიექტი ძებნა)
            // Token economy: metadata + მოკლე excerpt, სრული ტექსტი არ გაიგზავნება
            $isCompactMode = $count > 3;
            $charLimit = $isCompactMode
                ? 1200
                : (int) config('openai.max_chars_per_decision', 7000);

            // სტრატეგია: matched excerpt პირველ რიგში, შემდეგ სრული ტექსტის დასაწყისი
            // matched chunks = query-ს ყველაზე რელევანტური ნაწილი
            $excerpt  = $d['excerpt']   ?? '';
            $fullText = $d['full_text'] ?? '';

            if ($isCompactMode) {
                // Compact: მხოლოდ პირველი 1200 სიმბოლო (metadata listing-ისთვის საკმარისია)
                $textToSend = mb_substr(!empty($excerpt) ? $excerpt : $fullText, 0, $charLimit);
                $status     = 'COMPACT';
            } elseif (!empty($excerpt) && mb_strlen($excerpt) <= $charLimit) {
                // matched chunks ლიმიტში ეტევა — ვაგზავნით + დამატებითი კონტექსტი
                $remaining   = $charLimit - mb_strlen($excerpt);
                $extra       = $remaining > 300 ? mb_substr($fullText, 0, $remaining) : '';
                $textToSend  = $excerpt . ($extra ? "\n\n---\n" . $extra : '');
                $status      = 'MATCHED EXCERPTS + CONTEXT';
            } else {
                // excerpt დიდია ან არ გვაქვს — სრული ტექსტი ლიმიტამდე
                $textToSend = mb_substr(!empty($excerpt) ? $excerpt : $fullText, 0, $charLimit);
                $status     = mb_strlen($fullText) > $charLimit ? 'TRUNCATED' : 'FULL';
            }

            $blocks[] = <<<BLOCK
--- DECISION #{$num} ---
{$meta}
Relevance Score: {$d['relevance_score']}
Chunks: {$d['chunk_count']} | Mode: {$status}

TEXT:
{$textToSend}
--- END DECISION #{$num} ---
BLOCK;
        }

        return "RETRIEVED COURT DECISIONS:\n\n" . implode("\n\n", $blocks);
    }

    private function buildMessages(
        string $systemPrompt,
        string $contextBlock,
        array  $historyMessages,
        string $userQuestion
    ): array {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'system', 'content' => $contextBlock],
        ];

        // Add recent conversation history (token-conscious: last N messages)
        foreach ($historyMessages as $msg) {
            if (in_array($msg['role'], ['user', 'assistant'])) {
                $messages[] = [
                    'role'    => $msg['role'],
                    'content' => mb_substr($msg['content'], 0, 3000), // cap old messages
                ];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userQuestion];

        return $messages;
    }
}
