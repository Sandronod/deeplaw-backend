<?php

namespace App\Services\AI;

use App\DTOs\ConfidenceResult;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * Generates a grounded legal answer.
     *
     * @param  string          $userQuestion
     * @param  array           $decisions        Enriched decisions (may contain evidence_notes, key_facts)
     * @param  array           $historyMessages  [{role, content}, ...]
     * @param  int             $totalFound
     * @param  string          $mode             find|summarize|compare|explain|advise|chat
     * @param  ConfidenceResult $confidence
     * @return string
     *
     * @throws RuntimeException
     */
    public function answer(
        string          $userQuestion,
        array           $decisions,
        array           $historyMessages = [],
        int             $totalFound = 0,
        string          $mode = 'explain',
        ConfidenceResult $confidence = new ConfidenceResult(0.0, 'none', ''),
    ): string {
        $systemPrompt = $this->buildSystemPrompt($mode, $confidence);
        $contextBlock = $this->buildContextBlock($decisions, $totalFound, $mode);
        $messages     = $this->buildMessages($systemPrompt, $contextBlock, $historyMessages, $userQuestion);

        try {
            $response = Http::retry(3, 800, fn($e) =>
                $e instanceof RequestException
                && in_array($e->response?->status(), [500, 502, 503, 529])
            )
                ->withToken($this->apiKey)
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

            Log::debug('OpenAILegalAnswerService: tokens', [
                'prompt_tokens'     => $data['usage']['prompt_tokens']     ?? null,
                'completion_tokens' => $data['usage']['completion_tokens'] ?? null,
                'total_tokens'      => $data['usage']['total_tokens']      ?? null,
                'model'             => $this->model,
                'mode'              => $mode,
                'confidence'        => $confidence->label,
            ]);

            return trim($data['choices'][0]['message']['content']);

        } catch (RequestException $e) {
            throw new RuntimeException('OpenAI Chat request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── System Prompt ─────────────────────────────────────────────────────────

    private function buildSystemPrompt(string $mode, ConfidenceResult $confidence): string
    {
        $modeInstruction    = $this->buildModeInstruction($mode);
        $confidenceSection  = $this->buildConfidenceInstruction($confidence);

        return <<<PROMPT
შენ ხარ პროფესიონალი იურიდიული ასისტენტი (Legal Copilot), რომელიც ეხმარება იურისტებს სასამართლო პრაქტიკის ანალიზში.

შენი მთავარი ამოცანაა:
- უპასუხო შეკითხვებს სამართლებრივად სწორად
- დაეყრდნო პირველ რიგში მოწოდებულ სასამართლო გადაწყვეტილებებს (CONTEXT)
- არ მოიგონო ფაქტები, საქმეები ან სამართლებრივი დეტალები

────────────────────────
📚 ცოდნის პრიორიტეტი (STRICT)
────────────────────────

1. CONTEXT (მოწოდებული სასამართლო გადაწყვეტილებები)
2. ზოგადი სამართლებრივი ცოდნა
3. თუ არც ერთი არ არის საკმარისი → თქვი რომ ინფორმაცია არასაკმარისია

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
   - ჩამოთვალე გამოყენებული საქმეები (case number, სასამართლო, წელი)
   - თითოეულზე მოკლედ: რა საკითხზეა და რა დაადგინა
   - 🔗 [სრული ტექსტი](https://www.supremecourt.ge/ka/fullcase/{case_id}/0)

2. 📌 ამონარიდი (Facts Extraction)
   - ამოიღე რეალური ფაქტები CONTEXT-დან
   - გამოიყენე EVIDENCE NOTES სექცია თუ CONTEXT-ში არსებობს
   - არ დაამატო საკუთარი ინტერპრეტაცია ამ ნაწილში

3. ⚖️ სამართლებრივი ანალიზი (Reasoning)
   - შეადარე საქმეები
   - ახსენი სასამართლოს მიდგომა
   - მიუთითე მსგავსება/განსხვავება

4. 🧠 დასკვნა (Conclusion)
   - მოკლე და მკაფიო პასუხი
   - რა არის სამართლებრივი პოზიცია

5. 📊 სანდოობა (Confidence)
   - მაღალი / საშუალო / დაბალი
   - ახსენი რატომ

────────────────────────
📊 CONFIDENCE RULES
────────────────────────

- მაღალი → პირდაპირი შესაბამისობა, რამდენიმე საქმე, ერთგვაროვანი პრაქტიკა
- საშუალო → ნაწილობრივი შესაბამისობა ან განსხვავებული პრაქტიკა
- დაბალი → სუსტი კავშირი ან არასაკმარისი მონაცემი

{$confidenceSection}

────────────────────────
⚠️ თუ CONTEXT ცარიელია
────────────────────────

- არ მოიგონო არაფერი
- უპასუხე ზოგადი სამართლებრივი პრინციპებით
- მიუთითე: "კონკრეტული სასამართლო პრაქტიკა ვერ მოიძებნა მოცემულ ბაზაში"

────────────────────────
📌 ACTIVE MODE: {$mode}
────────────────────────

{$modeInstruction}

────────────────────────
🚫 STRICT RULES
────────────────────────

- არ დაწერო "შეიძლება", "ალბათ" — თუ არ გაქვს საფუძველი CONTEXT-დან
- არ გამოიყენო ინფორმაცია რომელიც CONTEXT-ში არ არის
- არ შეცვალო სასამართლოს გადაწყვეტილება
- არ აურიო საქმეები ერთმანეთში
- ქართულ კითხვაზე → ქართულად; ინგლისურ კითხვაზე → ინგლისურად
- follow-up კითხვებში გამოიყენე წინა კონტექსტი

────────────────────────
🎯 მთავარი მიზანი
────────────────────────

იყავი: ✔ ზუსტი  ✔ არგუმენტირებული  ✔ გამჭვირვალე
არ იყო: ✘ გენერიკული chatbot  ✘ "ლამაზად მოსაუბრე" AI

შენი პასუხი უნდა ჰგავდეს იურისტის ანალიზს, არა Google-ის პასუხს.
PROMPT;
    }

    private function buildModeInstruction(string $mode): string
    {
        return match ($mode) {
            'find' => <<<INST
მომხმარებელს სურს გადაწყვეტილებების მოძებნა.

პასუხის სტრუქტურა:
1. მოკლე შეჯამება: "მოიძებნა N გადაწყვეტილება [თემის შესახებ]"
2. ჩამონათვალი — თითოეულ საქმეზე:
   • საქმის ნომერი (case_num)
   • თარიღი
   • კატეგორია / სასამართლო
   • საქმის ძირითადი საგანი
   • გადაწყვეტილების შედეგი (1-2 წინადადება)
   • 🔗 ლინკი
3. თუ ბევრია (>5): დაჯგუფე თემატიკის ან პასუხის ტიპის მიხედვით

ყურადღება: არ გაამახვილო ყურადღება სამართლებრივ ანალიზზე — მომხმარებელს სია სჭირდება.
INST,

            'summarize' => <<<INST
მომხმარებელს სურს გადაწყვეტილების შეჯამება.

თუ ერთი საქმეა:
1. **საქმის ზოგადი მონაცემები:** case_num, თარიღი, სასამართლო, კატეგორია
2. **ფაქტობრივი გარემოებები:** რა მოხდა? ვინ მოდავე მხარეები?
3. **სამართლებრივი საკითხი:** რა გადასაწყვეტი იყო?
4. **სასამართლოს მსჯელობა:** რა ლოგიკით მოახდინა სასამართლომ შეფასება?
5. **გადაწყვეტილება:** საბოლოო შედეგი
6. **მნიშვნელობა:** რატომ არის ეს საქმე პრეცედენტულად ან პრაქტიკულად საინტერესო?
7. 🔗 ლინკი

თუ რამდენიმე საქმეა: შეჯამება ცალ-ცალკე, მერე საერთო დასკვნა.
INST,

            'compare' => <<<INST
მომხმარებელს სურს გადაწყვეტილებების შედარება.

სტრუქტურა:
1. **მიმოხილვა:** რომელი საქმეები შედარდება და რა საფუძველზე
2. **შედარებითი სექციები:**
   • ფაქტობრივი გარემოებები: მსგავსება / განსხვავება
   • სამართლებრივი საკითხი: მსგავსება / განსხვავება
   • სასამართლოს მსჯელობა: მიდგომა / ლოგიკა
   • საბოლოო გადაწყვეტილება: შედეგი
3. **ანალიზი:** რა განაპირობებს განსხვავებას? (ფაქტები? ნორმა? სასამართლო?)
4. **დასკვნა:** პრაქტიკული დასკვნა — რა შეიძლება ისწავლოს ამ შედარებიდან?

მკაფიოდ მონიშნე: 📋 (ბაზიდან) vs 💡 (ანალიტიკური დასკვნა)
INST,

            'explain' => <<<INST
მომხმარებელს სურს სამართლებრივი საკითხის განმარტება.

გამოიყენე IRAC სტრუქტურა:

📌 **საკითხი:** განსაზღვრე კონკრეტული სამართლებრივი კითხვა
⚖️ **ნორმა/პრინციპი:**
   • 📋 რას ამბობს ბაზიდან მოძიებული სასამართლო პრაქტიკა?
   • 💡 რომელი ზოგადი სამართლებრივი პრინციპი მოქმედებს? (თუ საჭიროა)
🔍 **გამოყენება:** როგორ ვრცელდება ეს კონკრეტულ კითხვაზე?
✅ **დასკვნა:** სამართლებრივი პასუხი

პასუხი უნდა იყოს ლოგიკურად სტრუქტურირებული, არა მხოლოდ ტექსტის გამეორება.
INST,

            'advise' => <<<INST
მომხმარებელს სურს პრაქტიკული სამართლებრივი ორიენტაცია.

⚠️ ᲛᲜᲘᲨᲕᲜᲔᲚᲝᲕᲐᲜᲘ: შენ არ ხარ ადვოკატი. პასუხი არ არის იურიდიული კონსულტაცია.

სტრუქტურა:
1. **ვითარების გაგება:** მოკლედ ახსენი გასაგებ ენაზე, რა სიტუაციაა
2. **პრაქტიკა:**
   📋 რა ამბობს სასამართლო პრაქტიკა მსგავს სიტუაციებში?
3. **ვარიანტები:** რა ნაბიჯები შეიძლება გადადგას მომხმარებელმა?
4. **შეზღუდვა:**
   ⚠️ "ეს ზოგადი ინფორმაციაა. კონკრეტული გადაწყვეტისთვის მიმართეთ კვალიფიციურ იურისტს."

ენა — მარტივი, გასაგები. ტექნიკური ტერმინები განმარტე.
INST,

            default => <<<INST
ეს ზოგადი სამართლებრივი კითხვაა. გამოიყენე ყველაზე შესაფერისი სტრუქტურა
კითხვის ტიპისა და ბაზიდან მოძიებული მასალის მიხედვით.
INST,
        };
    }

    private function buildConfidenceInstruction(ConfidenceResult $confidence): string
    {
        $explanation = $confidence->explanation;

        return match ($confidence->label) {
            'high' => <<<INST
RETRIEVAL სტატუსი: მაღალი სანდოობა [{$explanation}]
მოიძებნა პირდაპირ შესაბამისი გადაწყვეტილებები. გამოიყენე ისინი პასუხის საფუძვლად.
ფაქტობრივი ნაწილი — მხოლოდ ბაზიდან. ზოგადი ცოდნა — მხოლოდ სამართლებრივი კონტექსტისთვის.
INST,

            'medium' => <<<INST
RETRIEVAL სტატუსი: საშუალო სანდოობა [{$explanation}]
მოძიებული გადაწყვეტილებები სავარაუდოდ რელევანტურია, მაგრამ კავშირი ზუსტი არ არის.
გამოიყენე ბაზა, მაგრამ მიუთითე: "ბაზაში მოძიებული გადაწყვეტილებები სრულად ზუსტ შესაბამისობაში
არ არის კითხვასთან, თუმცა სავარაუდო კავშირი ასეთია..."
INST,

            'low' => <<<INST
RETRIEVAL სტატუსი: დაბალი სანდოობა [{$explanation}]
⚠️ ANTI-HALLUCINATION GUARD ACTIVE
მოძიებული მასალა სუსტ კავშირს ამყარებს. სავალდებულო ქცევა:
1. პასუხის დასაწყისში წარწერა: "⚠️ შეზღუდული პრაქტიკა: ბაზაში პირდაპირი შესაბამისობა ვერ მოიძებნა."
2. ნათლად განასხვავე: 📋 (ბაზიდან) vs 💡 (ზოგადი სამართლებრივი ცოდნა)
3. ნუ გამოიყენებ არარელევანტური გადაწყვეტილებების ფაქტებს
4. ბოლოში: "🔍 გირჩევ დახვეწილი ძებნა უფრო სპეციფიური ტერმინებით."
INST,

            'none' => <<<INST
RETRIEVAL სტატუსი: შედეგი არ მოიძებნა (NO_RESULTS)
⚠️ ANTI-HALLUCINATION GUARD: MAXIMUM LEVEL
ბაზაში ამ კითხვასთან შესაბამისი გადაწყვეტილება ვერ მოიძებნა.

სავალდებულო ქცევა:
1. პირდაპირ აცნობე: "⚠️ ბაზაში ამ კონკრეტულ თემაზე გადაწყვეტილება ვერ მოიძებნა."
2. შეგიძლია გააგრძელო ზოგადი სამართლებრივი ცოდნის საფუძველზე — 💡 ნიშნით
3. ნათლად განასხვავე: ეს არ არის სასამართლო პრაქტიკა, ეს არის ზოგადი სამართლებრივი ცოდნა
4. გაფრთხილება: "⚠️ კონკრეტული სასამართლო პრაქტიკისთვის მიმართეთ ბაზას ან იურისტს."
INST,

            default => '',
        };
    }

    // ── Context Block ─────────────────────────────────────────────────────────

    private function buildContextBlock(array $decisions, int $totalFound = 0, string $mode = 'explain'): string
    {
        if (empty($decisions)) {
            return "STATUS: NO_RESULTS\nმონაცემთა ბაზაში შესაბამისი გადაწყვეტილება ვერ მოიძებნა.";
        }

        $count     = count($decisions);
        $totalNote = ($totalFound > $count)
            ? " (სულ ბაზაში: {$totalFound} — ნაჩვენებია: {$count})"
            : "";

        $blocks = ["STATUS: FOUND — {$count} გადაწყვეტილება{$totalNote}\n"];

        foreach ($decisions as $i => $d) {
            $num  = $i + 1;
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

            // Quality flags notice
            $qualityNote = '';
            if (!empty($d['quality_flags'])) {
                $qualityNote = "\nQUALITY FLAGS: " . implode(', ', $d['quality_flags']);
            }

            [$textToSend, $status] = $this->resolveTextStrategy($d, $count, $mode);

            // Evidence notes from EvidenceBuilderService
            $evidenceSection = '';
            if (!empty($d['evidence_notes'])) {
                $notes = implode("\n- ", $d['evidence_notes']);
                $evidenceSection = "\nEVIDENCE NOTES (relevant passages):\n- {$notes}";
            }

            // Key facts
            $keyFactsSection = '';
            if (!empty($d['key_facts'])) {
                $facts = implode("\n- ", $d['key_facts']);
                $keyFactsSection = "\nKEY FACTS:\n- {$facts}";
            }

            $blocks[] = <<<BLOCK
--- DECISION #{$num} ---
{$meta}
Relevance Score: {$d['relevance_score']} | Chunks: {$d['chunk_count']} | Mode: {$status}{$qualityNote}{$keyFactsSection}{$evidenceSection}

TEXT:
{$textToSend}
--- END DECISION #{$num} ---
BLOCK;
        }

        return "RETRIEVED COURT DECISIONS:\n\n" . implode("\n\n", $blocks);
    }

    /**
     * Mode-aware text extraction strategy.
     *
     * find      → compact excerpt (1200)
     * summarize → full text (7000)
     * compare   → excerpt + context (3500 / 2000)
     * explain   → matched excerpt + surrounding context (7000)
     * advise    → matched excerpt (4000)
     */
    private function resolveTextStrategy(array $d, int $totalDecisions, string $mode): array
    {
        $excerpt  = $d['excerpt']   ?? '';
        $fullText = $d['full_text'] ?? '';

        if ($mode === 'find' || $totalDecisions > 5) {
            $text = mb_substr(!empty($excerpt) ? $excerpt : $fullText, 0, 1200);
            return [$text, 'COMPACT'];
        }

        if ($mode === 'summarize') {
            $limit = (int) config('openai.max_chars_per_decision', 7000);
            $text  = mb_substr($fullText ?: $excerpt, 0, $limit);
            $label = mb_strlen($fullText) > $limit ? 'TRUNCATED' : 'FULL';
            return [$text, $label];
        }

        if ($mode === 'compare') {
            $limit = $totalDecisions <= 2 ? 3500 : 2000;
            $text  = mb_substr(!empty($excerpt) ? $excerpt : $fullText, 0, $limit);
            return [$text, 'COMPARE'];
        }

        // explain / advise — excerpt + surrounding context
        $limit = (int) config('openai.max_chars_per_decision', 7000);
        if (!empty($excerpt) && mb_strlen($excerpt) <= $limit) {
            $remaining = $limit - mb_strlen($excerpt);
            $extra     = $remaining > 500 ? mb_substr($fullText, 0, $remaining) : '';
            $text      = $excerpt . ($extra ? "\n\n---\n" . $extra : '');
            return [$text, 'MATCHED+CONTEXT'];
        }

        $text  = mb_substr(!empty($excerpt) ? $excerpt : $fullText, 0, $limit);
        $label = mb_strlen($fullText) > $limit ? 'TRUNCATED' : 'FULL';
        return [$text, $label];
    }

    // ── Streaming ─────────────────────────────────────────────────────────────

    /**
     * Streams GPT tokens via Guzzle streaming + SSE parsing.
     * Yields each non-empty string token as it arrives.
     *
     * @throws RuntimeException on connection failure
     */
    public function streamTokens(
        string          $userQuestion,
        array           $decisions,
        array           $historyMessages = [],
        int             $totalFound = 0,
        string          $mode = 'explain',
        ConfidenceResult $confidence = new ConfidenceResult(0.0, 'none', ''),
    ): \Generator {
        $systemPrompt = $this->buildSystemPrompt($mode, $confidence);
        $contextBlock = $this->buildContextBlock($decisions, $totalFound, $mode);
        $messages     = $this->buildMessages($systemPrompt, $contextBlock, $historyMessages, $userQuestion);

        $client = new GuzzleClient();

        $response = $client->post("{$this->baseUrl}/chat/completions", [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'text/event-stream',
            ],
            'json' => [
                'model'       => $this->model,
                'messages'    => $messages,
                'max_tokens'  => $this->maxTokens,
                'temperature' => $this->temperature,
                'stream'      => true,
            ],
            'stream'  => true,
            'timeout' => $this->timeout,
        ]);

        $body   = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $chunk  = $body->read(4096);
            $buffer .= $chunk;

            // Process all complete lines in the buffer
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line   = trim($line);

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    return;
                }

                $json  = json_decode($data, true);
                $token = $json['choices'][0]['delta']['content'] ?? '';

                if ($token !== '') {
                    yield $token;
                }
            }
        }
    }

    // ── Message Assembly ──────────────────────────────────────────────────────

    private function buildMessages(
        string $systemPrompt,
        string $contextBlock,
        array  $historyMessages,
        string $userQuestion
    ): array {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt . "\n\n" . $contextBlock],
        ];

        foreach ($historyMessages as $msg) {
            if (in_array($msg['role'], ['user', 'assistant'])) {
                $messages[] = [
                    'role'    => $msg['role'],
                    'content' => mb_substr($msg['content'], 0, 3000),
                ];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userQuestion];

        return $messages;
    }
}
