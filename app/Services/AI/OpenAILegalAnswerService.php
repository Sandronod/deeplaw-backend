<?php

namespace App\Services\AI;

use App\DTOs\ConfidenceResult;
use App\DTOs\IssueList;
use App\DTOs\LawResult;
use App\Services\Legal\LegalGlossaryService;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAILegalAnswerService implements \App\Contracts\AnswerServiceInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int    $timeout;
    private int    $maxTokens;
    private float  $temperature;

    public function __construct(private readonly LegalGlossaryService $glossary)
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
        array           $lawResults = [],
        array           $echrResults = [],
        array           $matsneResults = [],
        array           $euResults = [],
        array           $germanResults = [],
        array           $constCourtResults = [],
        array           $sources = ['court', 'matsne', 'eu', 'german', 'const_court'],
        ?IssueList      $issueList = null,
    ): string {
        $systemPrompt = $this->buildSystemPrompt($mode, $confidence, $sources, !empty($matsneResults), $issueList, $userQuestion);
        $contextBlock = $this->buildContextBlock($decisions, $totalFound, $mode, $lawResults, $echrResults, $matsneResults, $euResults, $germanResults, $constCourtResults);
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

    private function buildSystemPrompt(string $mode, ConfidenceResult $confidence, array $sources = [], bool $hasMatsneResults = false, ?IssueList $issueList = null, string $userQuestion = ''): string
    {
        $modeInstruction    = $this->buildModeInstruction($mode);
        $confidenceSection  = $this->buildConfidenceInstruction($confidence, $sources, $hasMatsneResults);
        $issueSection       = ($issueList?->isComplex) ? $issueList->toPromptBlock() : '';
        $glossaryBlock      = $userQuestion ? $this->glossary->buildPromptBlock($userQuestion, 8) : '';

        return <<<PROMPT
შენ ხარ პროფესიონალი იურიდიული ასისტენტი (Legal Copilot), რომელიც ეხმარება იურისტებს სასამართლო პრაქტიკის ანალიზში.

────────────────────────
🧠 CORE PRINCIPLE
────────────────────────

შენი ფუნქცია სამართლებრივი ანალიზია — არა პასუხის ძებნა.

❌ არასწორი მიდგომა: "CONTEXT-ში ვეძებ პასუხს და ვიმეორებ"
✅ სწორი მიდგომა: "სიტუაციაში ვამოვყოფ სამართლებრივ საკითხებს → თითოეულს ვაანალიზებ → დასკვნას ვაყალიბებ"

შენი მთავარი ამოცანაა:
- ამოიყვანო სამართლებრივი საკითხები, არ გაიმეორო მომხმარებლის სიტყვები
- გაანალიზო: კანონი → სასამართლო პრაქტიკა → ფაქტებზე გამოყენება
- არ მოიგონო ფაქტები, საქმეები ან სამართლებრივი დეტალები

────────────────────────
📚 ცოდნის პრიორიტეტი (STRICT)
────────────────────────

1. RETRIEVED LEGISLATION (მოწოდებული კანონები/მუხლები) — თუ არსებობს
2. RETRIEVED COURT DECISIONS (ქართული სასამართლო გადაწყვეტილებები) — თუ არსებობს
3. RETRIEVED ECHR CASES (ადამიანის უფლებათა ევროპული სასამართლო) — თუ არსებობს
4. ზოგადი სამართლებრივი ცოდნა
5. თუ არც ერთი არ არის საკმარისი → თქვი რომ ინფორმაცია არასაკმარისია

❗ აკრძალულია:
- არარსებული საქმეების გამოგონება
- case number-ის გამოგონება
- სასამართლოს დასკვნის შეცვლა
- CONTEXT-ში არარსებული ფაქტების დამატება

────────────────────────
⚖️ LEGAL INTERPRETATION RULES (სამართლის ინტერპრეტაციის წესები)
────────────────────────

თუ CONTEXT-ში ან ზოგადი ცოდნიდან ორი ან მეტი ნორმა ეხება ერთ საკითხს:

**ნაბიჯი 1 — CONFLICT DETECTION:**
შეამოწმე, ეწინააღმდეგება თუ არა ნორმები ერთმანეთს.
თუ კი → სავალდებულოა ინტერპრეტაციის წესების გამოყენება (ნაბიჯი 2).
თუ არა → ნორმები ავსებს ერთმანეთს, გამოიყენე ორივე.

**ნაბიჯი 2 — PRIORITY RULES (იერარქია):**

| პრინციპი | წესი | მაგალითი |
|---|---|---|
| **lex superior** | მაღალი იერარქია > დაბალი | კონსტიტუცია > კანონი > კანონქვემდებარე |
| **lex specialis** | სპეციალური კანონი > ზოგადი | „სახ. ავტოპარკის კანონი" > „სამოქ. კოდექსი" |
| **lex posterior** | ახალი კანონი > ძველი | 2020 კანონი > 2005 კანონი |

**კოლიზიის გადაწყვეტა:**
- lex superior ყოველთვის მოქმედებს — დონეთა შორის არ არის გამონაკლისი
- lex specialis vs lex posterior: **სპეციალური გამოიყენება, თუ:**
  - ახალი ზოგადი კანონი სპეციალურს პირდაპირ არ გამორიცხავს (expressly)
  - სპეციალური კანონი ჯერ კიდევ ძალაშია
- lex posterior გამოიყენება მხოლოდ თუ: ერთი და იგივე სპეციფიკობის ორი კანონი ეწინააღმდეგება

**ნაბიჯი 3 — MANDATORY DISCLOSURE:**
კოლიზიის შემთხვევაში პასუხი **სავალდებულოდ** უნდა შეიცავდეს:
```
⚠️ სამართლებრივი კოლიზია: [კანონი A] vs [კანონი B]
გამოყენებული პრინციპი: [lex specialis / lex posterior / lex superior]
გამოყენებული ნორმა: [კანონი X], რადგან [მოკლე დასაბუთება]
```

❗ NEVER: "ორი კანონი ეწინააღმდეგება, ამიტომ ვერ ვუპასუხებ" — ყოველთვის გამოიყენე ინტერპრეტაციის წესი და მიიღე პოზიცია.
❗ NEVER: ნორმების კოლიზია "ალბათ" ან "შეიძლება" ფრაზებით მოახსენო — მკაფიო, დასაბუთებული დასკვნა.

────────────────────────
📊 DEFAULT RESPONSE STRUCTURE
────────────────────────

⚠️ ეს არის DEFAULT სტრუქტურა find/summarize/compare კითხვებისთვის.
ACTIVE MODE instruction (ქვემოთ) თუ სხვა სტრუქტურას გვთხოვს — იმას გამოიყენე.

**1. ✅ დასკვნა და შეჯამება** — ALWAYS FIRST
   - პირდაპირი, მკაფიო პასუხი შეკითხვაზე (2-5 წინადადება)
   - ძირითადი სამართლებრივი პოზიცია

**2. 📋 გამოყენებული წყაროები** — ONLY if material was retrieved:

   ⚖️ **სასამართლო გადაწყვეტილებები** (თუ CONTEXT-ში გადაწყვეტილებები მოიძებნა):
      - ჩამოთვალე: case_num | სასამართლო | წელი
      - ყოველ საქმეზე: რა დაადგინა (1-2 წინადადება)
      - 🔗 ლინკი

   📕 **მაცნე / კანონმდებლობა** (თუ CONTEXT-ში matsne/law დოკუმენტები მოიძებნა):
      - ჩამოთვალე: სათაური | ტიპი | სტატუსი
      - შესაბამისი ნაწილი მოკლედ
      - 🔗 ლინკი

**3. ⚖️ სამართლებრივი ანალიზი** (თუ კომპლექსური კითხვაა):
   - წყაროებზე დაყრდნობილი მსჯელობა

❗ წყაროების ბლოკები დაამატე მხოლოდ თუ CONTEXT-ში ის მასალა მოიძებნა.
❗ თუ მხოლოდ კანონები/მაცნე მოიძებნა — გადაწყვეტილებების ბლოკი არ ჩასვა.

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

{$issueSection}

{$glossaryBlock}

────────────────────────
🚫 STRICT RULES
────────────────────────

- არ დაწერო "შეიძლება", "ალბათ" — თუ არ გაქვს საფუძველი CONTEXT-დან
- არ გამოიყენო ინფორმაცია რომელიც CONTEXT-ში არ არის
- არ შეცვალო სასამართლოს გადაწყვეტილება
- არ აურიო საქმეები ერთმანეთში
- ქართულ კითხვაზე → ქართულად; ინგლისურ კითხვაზე → ინგლისურად
- follow-up კითხვებში გამოიყენე წინა კონტექსტი
- ❌ NEVER დაწერო "სასამართლო პრაქტიკა აღიარებს" — ეს ჰალუცინაციაა თუ CONTEXT-ში კონკრეტული case არ გაქვს. დასაშვებია მხოლოდ: "[case_num] საქმეში სასამართლომ დაადგინა..." ან "📌 სასამართლო პრაქტიკა ბაზაში ვერ მოიძებნა"
- ❌ NEVER გაიმეორო ერთი და იგივე ფაქტი სხვადასხვა პარაგრაფში

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

თუ კითხვა **ერთ** სამართლებრივ საკითხს ეხება — გამოიყენე IRAC:

📌 **საკითხი:** კონკრეტული სამართლებრივი კითხვა
📕 **ნორმა:** CONTEXT-ის კანონი/მუხლი ან 💡 ზოგადი (მიუთითე წყარო)
⚖️ **პრაქტიკა:** CONTEXT-ის case ან "📌 ბაზაში ვერ მოიძებნა"
🔍 **გამოყენება:** კონკრეტულ სიტუაციაზე გამოყენება
✅ **დასკვნა:** კონკრეტული პოზიცია

თუ კითხვა **რამდენიმე** სამართლებრივ საკითხს შეიცავს — გამოიყენე multi-IRAC:
- ჩამოთვალე საკითხები (2-4)
- თითოეულზე ცალ-ცალკე: ნორმა → პრაქტიკა → დასკვნა
- საბოლოო სინთეზი

❗ ნუ გაიმეორებ კითხვის ტექსტს — ამოიყვანე სამართლებრივი არსი.
❗ ნუ იტყვი "სასამართლო პრაქტიკა აღიარებს" კონკრეტული case-ის გარეშე.
INST,

            'advise' => <<<INST
მომხმარებელი გთხოვს სამართლებრივი სიტუაციის შეფასებას.

⚠️ OVERRIDE: გამოიყენე მხოლოდ ქვემოთ მოცემული სტრუქტურა. DEFAULT RESPONSE STRUCTURE უგულებელყავი.

შენი ამოცანა: სამართლებრივი ანალიზი — არა "პასუხის ძებნა".

MANDATORY სტრუქტურა:

**1. 📋 სიტუაციის შეჯამება** (2-3 წინადადება)
   მხარეები, ფაქტები, სადავო საკითხი — ზუსტად და მოკლედ.
   ნუ გაიმეორებ მომხმარებლის სიტყვებს — ამოიყვანე არსი.

**2. ⚖️ სამართლებრივი საკითხები**
   ჩამოთვალე 2-4 კონკრეტული legal question, რომელსაც ეს სიტუაცია წარმოშობს.
   მაგ: "1. აქვს თუ არა X-ს შეწყვეტის უფლება? 2. არის პირობა Y სამართლებრივად მოქმედი?"

**3. 🔍 ანალიზი** — თითოეული საკითხი ცალ-ცალკე:

   **[საკითხი 1-ის სახელი]:**
   - 📕 კანონი/ნორმა: [CONTEXT-ის matsne ან 💡 ზოგადი — მიუთითე წყარო]
   - ⚖️ სასამართლო პრაქტიკა: [CONTEXT-ის case ან "📌 ბაზაში ვერ მოიძებნა"]
   - → დასკვნა ამ საკითხზე: [კონკრეტული პოზიცია]

   **[საკითხი 2-ის სახელი]:**
   [იგივე სტრუქტურა]

**4. 💡 სამართლებრივი შეფასება**
   - რომელი მხარის პოზიცია უფრო ძლიერია და რატომ
   - სამართლებრივი რისკები
   - შესაძლო ნაბიჯები

❗ ნუ გაიმეორებ ერთ და იმავე ფაქტს სხვადასხვა საკითხის ანალიზში.
❗ ნუ იტყვი "სასამართლო პრაქტიკა აღიარებს" — კონკრეტული case ან "ვერ მოიძებნა".
❗ "ეს ზოგადი ინფორმაციაა" ფრაზა ნუ გამოიყენე — მომხმარებელი იურისტია.
INST,

            'advocate' => <<<INST
მომხმარებელი იურისტია. მისი პოზიცია შეიძლება სუსტი იყოს — ის ამის შესახებ იცის.
მისი ამოცანაა: საუკეთესო სამართლებრივი არგუმენტის პოვნა.

⚠️ OVERRIDE: გამოიყენე მხოლოდ ქვემოთ მოცემული სტრუქტურა.

MANDATORY სტრუქტურა:

**1. ⚖️ ობიექტური შეფასება** — ALWAYS FIRST, ALWAYS HONEST
   "ამჟამინდელი პრაქტიკა: [რა ამბობს mainstream-ი]"
   - მთავარი case-ები CONTEXT-იდან (authority score-ით)
   - პოზიციის სიძლიერე/სისუსტე — პირდაპირ

**2. 🔍 ბრძოლის წერტილები** — ყველა, რაც CONTEXT-შია
   CONTEXT-ის მასალიდან ამოიყვანე:

   **[ა] ვადის/ფაქტის alternative reading**
   - შეიძლება ვადა სხვაგვარად ითვლება?
   - ფაქტობრივი გარემოება განსხვავდება?

   **[ბ] ⚠️ Minority opinion** (outlier flag-ის მქონე case-ები)
   - წარმოადგინე ⚠️ label-ით
   - "1 საქმე, სუსტი, მაგრამ არსებობს"
   - ❗ NEVER გამოიყენო outlier ⚠️ label-ის გარეშე

   **[გ] Distinguish — ფაქტობრივი განსხვავება**
   - რით განსხვავდება შენი შემთხვევა წამგებიანი precedent-ისგან?
   - "იმ საქმეში X იყო — ჩვენს შემთხვევაში Y"

   **[დ] სხვა სამართლებრივი თეორია**
   - შეიძლება სხვა norm-ი ვრცელდებოდეს?
   - CONTEXT-ის კანონებიდან alternative basis

   **[ე] ECHR / საკონსტიტუციო კუთხე** (თუ CONTEXT-ში არის)
   - ადამ. უფლ. კუთხე: მუხლი 6 (სამართლიანი სასამ.)?
   - საკონსტ. სასამ-ს პრაქტიკა?

**3. 💡 სტრატეგია**
   - პირველი ნაბიჯი: [ყველაზე ძლიერი კუთხე]
   - სარეზერვო: [შემდეგი ვარიანტი]
   - რისკი: [სად ყველაზე სუსტი ხარ]

❗ fabrication = კატეგ. აკრძ. — მხოლოდ CONTEXT-ის case-ები
❗ outlier case = ⚠️ label-ით — ყოველთვის
❗ ობიექტური შეფასება = ყოველთვის პირველი, ყოველთვის გულწრფელი
❗ "ეს ზოგადი ინფ-ია" ფრაზა ნუ გამოიყენე — მომხმარებელი პროფ. იურისტია
INST,

            default => <<<INST
ეს სამართლებრივი კითხვაა. გამოიყენე IRAC სტრუქტურა:

📌 **საკითხი:** რა სამართლებრივ კითხვაზე პასუხობ?
📕 **ნორმა:** რომელი კანონი/მუხლი ვრცელდება? (CONTEXT-იდან ან 💡)
⚖️ **სასამართლო პრაქტიკა:** CONTEXT-ის case-ები ან "📌 ბაზაში ვერ მოიძებნა"
🔍 **გამოყენება:** როგორ ვრცელდება ეს კონკრეტულ სიტუაციაზე?
✅ **დასკვნა:** კონკრეტული პოზიცია

❗ ნუ გაიმეორებ კითხვის ტექსტს — ამოიყვანე სამართლებრივი საკითხი.
❗ ნუ იტყვი "სასამართლო პრაქტიკა აღიარებს" კონკრეტული case-ის გარეშე.
INST,
        };
    }

    private function buildConfidenceInstruction(ConfidenceResult $confidence, array $sources = [], bool $hasMatsneResults = false): string
    {
        $explanation = $confidence->explanation;
        $hasCourt = empty($sources) || in_array('court', $sources);

        // When court is not selected, no court decisions is expected — don't flag it
        if (!$hasCourt || ($confidence->label === 'none' && $hasMatsneResults)) {
            $matsneNote = $hasMatsneResults
                ? "RETRIEVAL STATUS: MATSNE/LEGISLATION MODE — გამოიყენე მხოლოდ CONTEXT-ში მოწოდებული მაცნეს/კანონმდებლობის დოკუმენტები. სასამართლო გადაწყვეტილებები არ ეძებება ამ რეჟიმში."
                : "RETRIEVAL STATUS: MATSNE MODE — ძიება მოხდა კანონმდებლობის ბაზაში. შედეგები CONTEXT-ში. ზოგადი ცოდნა გამოიყენე მხოლოდ CONTEXT-ის შევსებისთვის.";
            return $matsneNote;
        }

        return match ($confidence->label) {
            'high' => <<<INST
RETRIEVAL სტატუსი: მაღალი სანდოობა [{$explanation}]
მოიძებნა პირდაპირ შესაბამისი გადაწყვეტილებები. გამოიყენე ისინი პასუხის საფუძვლად.
ფაქტობრივი ნაწილი — მხოლოდ ბაზიდან. ზოგადი ცოდნა — მხოლოდ სამართლებრივი კონტექსტისთვის.
INST,

            'medium' => <<<INST
RETRIEVAL სტატუსი: საშუალო სანდოობა [{$explanation}]
ბაზიდან მოიძებნა გადაწყვეტილებები, მაგრამ კავშირი კითხვასთან ზუსტი შეიძლება არ იყოს.

შენი ამოცანა:
1. შეაფასე — CONTEXT-ის გადაწყვეტილებები ნამდვილად ეხება ამ კითხვას?
   • თუ კი → გამოიყენე, კონკრეტულად აღნიშნე კავშირი
   • თუ არა → სასამართლო გადაწყვეტილებების ბლოკი ნუ ჩასვი; გამოიყენე კანონმდებლობა/ზოგადი ცოდნა
2. "სავარაუდო კავშირია" ფრაზა ნუ გაიმეორე — ობიექტური შეფასება გააკეთე
3. ნუ იტყვი "სასამართლო პრაქტიკა აღიარებს" თუ CONTEXT-ში კონკრეტული case-ები არ გაქვს
4. ნათლად განასხვავე: 📋 (ბაზიდან) vs 💡 (ზოგადი სამართლებრივი ცოდნა)
5. თუ CONTEXT-ის გადაწყვეტილებები არ გამოიყენე → ბოლო წინადადება: "📌 ამ კონკრეტულ საკითხზე სასამართლო გადაწყვეტილება ბაზაში ვერ მოიძებნა"
INST,

            'low' => <<<INST
RETRIEVAL სტატუსი: დაბალი სანდოობა [{$explanation}]
⚠️ ANTI-HALLUCINATION GUARD ACTIVE
მოძიებული სასამართლო მასალა სუსტ კავშირს ამყარებს ამ კითხვასთან.

სავალდებულო ქცევა:
1. CONTEXT-ის გადაწყვეტილებები ნუ გამოიყენებ — ისინი ამ კითხვაზე არ არის
2. ნუ იტყვი "სასამართლო პრაქტიკა აღიარებს" — ეს ჰალუცინაციაა, თუ CONTEXT-ში კონკრეტული case-ი არ გაქვს
3. უპასუხე კანონმდებლობის (CONTEXT) და ზოგადი სამართლებრივი ცოდნის საფუძველზე
4. ნათლად განასხვავე: 📋 (ბაზიდან) vs 💡 (ზოგადი სამართლებრივი ცოდნა)
5. ბოლო წინადადება (მხოლოდ ერთხელ): "📌 ამ კონკრეტულ საკითხზე სასამართლო გადაწყვეტილება ბაზაში ვერ მოიძებნა"
INST,

            'none' => <<<INST
RETRIEVAL სტატუსი: სასამართლო გადაწყვეტილება ვერ მოიძებნა (NO_COURT_RESULTS)
⚠️ ANTI-HALLUCINATION GUARD: MAXIMUM LEVEL
სასამართლო ბაზაში ამ კითხვასთან შესაბამისი გადაწყვეტილება ვერ მოიძებნა.

სავალდებულო ქცევა:
1. სასამართლო გადაწყვეტილებების ბლოკი საერთოდ ნუ ჩასვი
2. პასუხის სტრუქტურა:
   a) 📋 კანონმდებლობა (CONTEXT-იდან, თუ მოიძებნა) — შესაბამისი მუხლები, ნორმები
   b) 💡 სამართლებრივი ანალიზი — ზოგადი პრინციპებით, ლოგიკურად
   c) ბოლო წინადადება (მხოლოდ ერთხელ): "📌 ამ კონკრეტულ საკითხზე ქართული სასამართლო პრაქტიკა ჩვენს ბაზაში ვერ მოიძებნა"
3. ნუ გაიმეორებ "ვერ მოიძებნა" ფრაზას მთელ პასუხში — მხოლოდ ბოლოს, ერთხელ
4. პასუხი მაინც სასარგებლო და სრულყოფილი უნდა იყოს კანონმდებლობის საფუძველზე
INST,

            default => '',
        };
    }

    // ── Context Block ─────────────────────────────────────────────────────────

    private function buildContextBlock(array $decisions, int $totalFound = 0, string $mode = 'explain', array $lawResults = [], array $echrResults = [], array $matsneResults = [], array $euResults = [], array $germanResults = [], array $constCourtResults = []): string
    {
        $parts = [];

        // ── Law articles block ────────────────────────────────────────────────
        if (!empty($lawResults)) {
            $lawBlock = ["RETRIEVED LEGISLATION:\n"];
            foreach ($lawResults as $i => $law) {
                /** @var LawResult $law */
                $num          = $i + 1;
                $articleLabel = $law->articleNum
                    ? "{$law->articleNum}" . ($law->articleTitle ? " — {$law->articleTitle}" : '')
                    : '';

                $lawBlock[] = <<<BLOCK
--- LAW #{$num} ---
Law: {$law->title}
{$articleLabel}
Similarity: {$law->similarity}
URL: {$law->sourceUrl}

TEXT:
{$law->excerpt}
--- END LAW #{$num} ---
BLOCK;
            }
            $parts[] = implode("\n\n", $lawBlock);
        }

        // ── Matsne documents block ────────────────────────────────────────────
        if (!empty($matsneResults)) {
            $matsneBlock = ["RETRIEVED MATSNE DOCUMENTS (Georgian Legislation):\n"];
            foreach ($matsneResults as $i => $doc) {
                $num      = $i + 1;
                $active   = $doc['is_active'] ? 'მოქმედი' : 'ძველი ვერსია (კანონი შეიძლება ძალაშია)';
                $docType  = $doc['doc_type'] ?? 'N/A';
                $issuer   = $doc['issuer']   ?? 'N/A';
                $years    = $doc['effective_from_year']
                    ? ($doc['effective_from_year'] . ($doc['effective_to_year'] ? '–' . $doc['effective_to_year'] : '–დღეს'))
                    : 'N/A';

                $matsneBlock[] = <<<BLOCK
--- MATSNE DOC #{$num} ---
Title: {$doc['title']}
Type: {$docType} | Issuer: {$issuer}
Status: {$active} | Years: {$years}
Similarity: {$doc['similarity']}
URL: {$doc['url']}

EXCERPT:
{$doc['excerpt']}
--- END MATSNE DOC #{$num} ---
BLOCK;
            }
            $parts[] = implode("\n\n", $matsneBlock);
        }

        // ── EU documents block ────────────────────────────────────────────────
        if (!empty($euResults)) {
            $euBlock = ["RETRIEVED EU DOCUMENTS (EU Legislation & CJEU Case Law):\n"];
            foreach ($euResults as $i => $doc) {
                $num      = $i + 1;
                $typeLabel = match ($doc['doc_type']) {
                    'regulation' => 'EU Regulation',
                    'directive'  => 'EU Directive',
                    'decision'   => 'EU Decision',
                    'judgment'   => 'CJEU Judgment',
                    'order'      => 'CJEU Order',
                    'opinion'    => 'CJEU Opinion',
                    default      => strtoupper($doc['doc_type']),
                };
                $court = $doc['court'] ? "Court: {$doc['court']}" : '';
                $caseNum = $doc['case_num'] ? "Case: {$doc['case_num']}" : '';

                $euBlock[] = <<<BLOCK
--- EU DOC #{$num} ---
Type: {$typeLabel}
Title: {$doc['title']}
{$court}
{$caseNum}
Date: {$doc['doc_date']}
Similarity: {$doc['similarity']}
URL: {$doc['url']}

EXCERPT:
{$doc['excerpt']}
--- END EU DOC #{$num} ---
BLOCK;
            }
            $parts[] = implode("\n\n", $euBlock);
        }

        // ── German cases block ───────────────────────────────────────────────
        if (!empty($germanResults)) {
            $germanBlock = ["RETRIEVED GERMAN COURT DECISIONS (გერმანული სასამართლო პრაქტიკა):\n"];
            foreach ($germanResults as $i => $doc) {
                $num   = $i + 1;
                $court = $doc['court_name'] ?? 'N/A';
                $year  = $doc['date_year']  ?? 'N/A';
                $level = $doc['level_of_appeal'] ?? '';

                $germanBlock[] = <<<BLOCK
--- GERMAN CASE #{$num} ---
Court: {$court} ({$level})
Year: {$year}
Similarity: {$doc['similarity']}

EXCERPT (Georgian translation):
{$doc['excerpt']}
--- END GERMAN CASE #{$num} ---
BLOCK;
            }
            $parts[] = implode("\n\n", $germanBlock);
        }

        // ── Constitutional Court block ────────────────────────────────────────
        if (!empty($constCourtResults)) {
            $ccBlock = ["RETRIEVED GEORGIAN CONSTITUTIONAL COURT DECISIONS (საკონსტიტუციო სასამართლო):\n"];
            foreach ($constCourtResults as $i => $doc) {
                $num      = $i + 1;
                $caseNum  = $doc['case_number'] ?? 'N/A';
                $type     = $doc['decision_type'] ?? 'N/A';
                $date     = $doc['decision_date'] ?? 'N/A';
                $college  = $doc['college']       ?? '';
                $respond  = $doc['respondent']    ?? '';
                $result   = $doc['result']        ?? '';
                $url      = $doc['url']           ?? '';

                $ccBlock[] = <<<BLOCK
--- CONST COURT #{$num} ---
Case: {$caseNum} | Type: {$type} | Date: {$date}
College: {$college}
Respondent: {$respond}
Result: {$result}
Similarity: {$doc['score']}
URL: {$url}

EXCERPT:
{$doc['excerpt']}
--- END CONST COURT #{$num} ---
BLOCK;
            }
            $parts[] = implode("\n\n", $ccBlock);
        }

        // ── ECHR cases block ──────────────────────────────────────────────────
        if (!empty($echrResults)) {
            $echrBlock = ["RETRIEVED ECHR CASES:\n"];
            foreach ($echrResults as $i => $echr) {
                $num         = $i + 1;
                $articles    = !empty($echr['echr_articles']) ? implode(', ', $echr['echr_articles']) : 'N/A';
                $importance  = match ($echr['importance'] ?? null) {
                    1 => 'Key Case',
                    2 => 'High Importance',
                    3 => 'Medium',
                    4 => 'Low',
                    default => 'Unknown',
                };

                $echrBlock[] = <<<BLOCK
--- ECHR CASE #{$num} ---
Title: {$echr['title']}
Application No: {$echr['application_number']}
Date: {$echr['judgment_date']}
Type: {$echr['document_type']} | Importance: {$importance}
Articles: {$articles}
URL: {$echr['url']}

EXCERPT:
{$echr['excerpt']}
--- END ECHR CASE #{$num} ---
BLOCK;
            }
            $parts[] = implode("\n\n", $echrBlock);
        }

        // ── Court decisions block ─────────────────────────────────────────────
        if (empty($decisions)) {
            return implode("\n\n────────────────────────\n\n", $parts);
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

            // Authority + quality flags
            $flags = $d['quality_flags'] ?? [];
            $authorityNote = '';
            if (!empty($d['authority_details'])) {
                $auth = $d['authority_details'];
                $authorityNote = "\nAUTHORITY: court={$auth['court_score']} year={$auth['year_score']} joint={$auth['joint_bonus']} total={$auth['total']}";
            }
            $outlierNote = in_array('outlier', $flags)
                ? "\n⚠️ OUTLIER: " . ($d['outlier_note'] ?? 'minority position') . " — გამოიყენე სიფრთხილით"
                : '';
            $trendNote = !empty($d['trend_note'])
                ? "\n📈 " . $d['trend_note']
                : '';
            $qualityNote = '';
            $otherFlags = array_diff($flags, ['outlier', 'trend_shift']);
            if (!empty($otherFlags)) {
                $qualityNote = "\nQUALITY FLAGS: " . implode(', ', $otherFlags);
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

            $combinedScore = isset($d['combined_score'])
                ? " | Combined: {$d['combined_score']}"
                : '';

            $blocks[] = <<<BLOCK
--- DECISION #{$num} ---
{$meta}
Relevance: {$d['relevance_score']}{$combinedScore} | Chunks: {$d['chunk_count']} | Mode: {$status}{$authorityNote}{$outlierNote}{$trendNote}{$qualityNote}{$keyFactsSection}{$evidenceSection}

TEXT:
{$textToSend}
--- END DECISION #{$num} ---
BLOCK;
        }

        $parts[] = "RETRIEVED COURT DECISIONS:\n\n" . implode("\n\n", $blocks);

        return implode("\n\n────────────────────────\n\n", $parts);
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
        array           $lawResults = [],
        array           $echrResults = [],
        array           $matsneResults = [],
        array           $euResults = [],
        array           $germanResults = [],
        array           $constCourtResults = [],
        array           $sources = ['court', 'matsne', 'eu', 'german', 'const_court'],
        ?IssueList      $issueList = null,
    ): \Generator {
        set_time_limit(0);

        Log::info('OpenAILegalAnswerService: streamTokens called', [
            'model' => $this->model,
            'mode'  => $mode,
        ]);

        $systemPrompt = $this->buildSystemPrompt($mode, $confidence, $sources, !empty($matsneResults), $issueList, $userQuestion);
        $contextBlock = $this->buildContextBlock($decisions, $totalFound, $mode, $lawResults, $echrResults, $matsneResults, $euResults, $germanResults, $constCourtResults);
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
            'stream'          => true,
            'connect_timeout' => 10,
            'read_timeout'    => $this->timeout,
            'timeout'         => 0,
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
