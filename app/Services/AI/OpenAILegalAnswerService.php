<?php

namespace App\Services\AI;

use App\DTOs\ConfidenceResult;
use App\DTOs\EchrResult;
use App\DTOs\IssueList;
use App\DTOs\LawResult;
use App\DTOs\TriageResult;
use App\Services\AI\CitationVerifierService;
use App\Services\AI\LegalRuleExtractorService;
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

    public function __construct(
        private readonly LegalGlossaryService       $glossary,
        private readonly CitationVerifierService    $citationVerifier,
        private readonly LegalRuleExtractorService  $ruleExtractor,
        private readonly LegalRemedyGuardService    $remedyGuard,
        private readonly LegalConsequenceTaxonomyService $consequenceTaxonomy,
        private readonly LegalSourceCoverageGuardService $sourceCoverageGuard,
        private readonly OpenAIUsageTrackerService  $usageTracker,
    ) {
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
        array           $sources = [],
        ?IssueList      $issueList = null,
        ?TriageResult   $triage = null,
        array           $extractedRules = [],
    ): string {
        $model = $this->modelForRequest($userQuestion, $mode, $triage, $issueList);
        $systemPrompt = $this->buildSystemPrompt($mode, $confidence, $sources, !empty($matsneResults), $issueList, $userQuestion, $triage);
        $rulesBlock   = $this->ruleExtractor->buildPromptBlock($extractedRules);
        $remedyBlock  = $this->remedyGuard->buildPromptBlock($userQuestion, $matsneResults, $decisions, $triage);
        $contextBlock = implode("\n\n", array_filter([
            $rulesBlock,
            $remedyBlock,
            $this->buildContextBlock($decisions, $totalFound, $mode, $lawResults, $echrResults, $matsneResults, $euResults, $germanResults, $constCourtResults, $triage),
        ]));
        $messages     = $this->buildMessages($systemPrompt, $contextBlock, $historyMessages, $userQuestion);

        try {
            $response = Http::retry(3, 800, fn($e) =>
                $e instanceof RequestException
                && in_array($e->response?->status(), [500, 502, 503, 529])
            )
                ->withToken($this->apiKey)
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $model,
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
                'model'             => $model,
                'default_model'     => $this->model,
                'mode'              => $mode,
                'confidence'        => $confidence->label,
            ]);

            $this->usageTracker->recordChat('answer_generation', $model, $data['usage'] ?? null);

            return trim($data['choices'][0]['message']['content']);

        } catch (RequestException $e) {
            throw new RuntimeException('OpenAI Chat request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── System Prompt ─────────────────────────────────────────────────────────

    private function buildSystemPrompt(string $mode, ConfidenceResult $confidence, array $sources = [], bool $hasMatsneResults = false, ?IssueList $issueList = null, string $userQuestion = '', ?TriageResult $triage = null): string
    {
        $modeInstruction    = $this->buildModeInstruction($mode);
        $confidenceSection  = $this->buildConfidenceInstruction($confidence, $sources, $hasMatsneResults);
        $issueSection       = ($issueList?->isComplex) ? $issueList->toPromptBlock() : '';
        $glossaryBlock      = $userQuestion ? $this->glossary->buildPromptBlock($userQuestion, 8) : '';
        $domainContext      = $this->buildDomainContextBlock($triage);
        $statutoryRules     = $this->buildStatutoryRulesBlock($triage, $userQuestion);
        $sourceCoverage     = $this->sourceCoverageGuard->buildPromptBlock($userQuestion, $triage, $issueList);
        $responseBudget     = $this->buildResponseBudgetInstruction($mode, $triage);

        return <<<PROMPT
შენ ხარ პროფესიონალი იურიდიული ასისტენტი (Legal Copilot), რომელიც ეხმარება იურისტებს სასამართლო პრაქტიკის ანალიზში.

{$responseBudget}

{$domainContext}

{$statutoryRules}

{$sourceCoverage}

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

1. RETRIEVED LEGISLATION — SPECIFIC ARTICLES (📌 explicit / 💡 concept-injected)
   ეს არის კონკრეტული მუხლების ტექსტი ბაზიდან. **სავალდებულოა გამოიყენო.**
   📌 explicit citation = მომხმარებელმა პირდაპირ დაასახელა ეს მუხლი
   💡 concept-injected = სისტემამ ავტომატ. ამოიცნო, რომ ეს მუხლი რელევანტურია
   → ორივე შემთხვევაში: წაიკითხე, გაანალიზე, გამოიყენე

2. RETRIEVED COURT DECISIONS (ქართული სასამართლო გადაწყვეტილებები) — თუ არსებობს
3. RETRIEVED ECHR CASES (ადამიანის უფლებათა ევროპული სასამართლო) — თუ არსებობს
4. ზოგადი სამართლებრივი ცოდნა
5. თუ არც ერთი არ არის საკმარისი → თქვი რომ ინფორმაცია არასაკმარისია

────────────────────────
⚖️ SOURCE AUTHORITY STATUS (STRICT)
────────────────────────

ყოველ retrieved წყაროს შეიძლება ჰქონდეს AUTHORITY_STATUS.
ეს სტატუსი განსაზღვრავს, როგორ შეიძლება წყაროს გამოყენება:

- binding_legislation — სავალდებულო კანონმდებლობა.
- constitutional_binding_erga_omnes — საკონსტიტუციო სასამართლოს სავალდებულო erga omnes ეფექტი, თუ excerpt/result ამას ადასტურებს.
- binding_full_chamber — უზენაესი სასამართლოს სრული/გაერთიანებული შემადგენლობის ძლიერი/სავალდებულო განმარტება.
- persuasive_supreme / persuasive_appellate / persuasive_lower_court — persuasive პრაქტიკა; არ უწოდო სავალდებულო პრეცედენტი.
- supporting_analogy — მხოლოდ დამხმარე ანალოგია.
- echr_interpretive_authority — კონვენციის სტანდარტის განმარტება; არ აურიო საქართველოს შიდა სასამართლო პრაქტიკასთან.
- comparative_non_binding — შედარებითი წყარო; ქართულ სამართალში არასავალდებულოა.

❗ NEVER: ordinary Supreme/Appellate/Lower court decision-ს არ უწოდო "სავალდებულო პრეცედენტი", თუ AUTHORITY_STATUS არ არის binding_full_chamber.
❗ NEVER: German/EU comparative source არ წარმოადგინო ქართულ სამართალში სავალდებულო წყაროდ.
❗ ALWAYS: თუ წყარო მხოლოდ persuasive/comparative/supporting არის, პასუხში გამოიყენე რბილი ენა: "სასარგებლო შედარებითი არგუმენტი", "დამხმარე პრაქტიკა", "ანალოგიური მიდგომა".

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
      - თუ საქმე მონიშნულია PRIMARY AUTHORITY-ად — გამოიყენე როგორც მთავარი პრაქტიკა
      - თუ საქმე მონიშნულია SUPPORTING ANALOGY-ად — გამოიყენე მხოლოდ დამხმარე მსგავს პრაქტიკად
      - ჩამოთვალე: case_num | სასამართლო | წელი
      - ყოველ საქმეზე: რა დაადგინა (1-2 წინადადება)
      - 🔗 ლინკი
      - ❗ თუ PRIMARY AUTHORITY არსებობს, არ დაწერო "ბაზაში ვერ მოიძებნა"; cite case_num და მიუთითე, პირდაპირია თუ მხოლოდ ანალოგიური.
      - ❗ ყველა PRIMARY AUTHORITY case_num ზუსტად უნდა გამოჩნდეს პასუხში; არც ერთი primary case არ გამოტოვო.

   📕 **მაცნე / კანონმდებლობა** (თუ CONTEXT-ში matsne/law დოკუმენტები მოიძებნა):
      - ჩამოთვალე: სათაური | ტიპი | სტატუსი
      - თუ CONTEXT-ში article_detector/concept_detector-ით ამოსულია კონკრეტული მუხლი, წყაროში აუცილებლად დაწერე კანონი + მუხლის ნომერი; არ დაწერო მხოლოდ „სამოქალაქო კოდექსი“, „შრომის კოდექსი“ ან „მუხლები მოძიებული არ არის“.
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
✅ CONCLUSION RULES (სავალდებულო)
────────────────────────

**დასკვნა ყოველთვის კონკრეტული უნდა იყოს:**
- ✅ "სარჩელი დასაშვებია / არ არის დასაშვები"
- ✅ "ხანდაზმულობა გასულია / არ გასულა — [X წელი + [თარიღი]]"
- ✅ "გარიგება ბათილია / ნამდვილია სკ [X] მუხლის საფუძველზე"
- ✅ "მხარეს აქვს / არ აქვს უფლება — [კანონი + მიზეზი]"

❌ NEVER დაწერო:
- "შეიძლება ბათილი იყოს" → "ბათილია, რადგან..."
- "სავარაუდოდ ვადა გასულია" → "ვადა გასულია: [თარიღი + გაანგარიშება]"
- "ეს კომპლექსური საკითხია" → ეს არ არის დასკვნა
- "კონკრეტული შემთხვევიდან გამომდინარე..." → მიიღე პოზიცია

❗ თუ ფაქტები არ კმარა — **ამის შესახებ პირდაპირ თქვი** და მოითხოვე კონკრეტული ინფო, ნუ "ბუნდოვნობ".

────────────────────────
🚫 STRICT RULES
────────────────────────

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

    private function buildResponseBudgetInstruction(string $mode, ?TriageResult $triage): string
    {
        $level = (string) ($triage?->complexityLevel ?? 'normal');
        $isResearchMode = in_array($mode, ['advocate', 'compare', 'summarize', 'find'], true)
            || in_array($level, ['complex', 'full'], true);

        if ($isResearchMode) {
            return <<<INST
RESPONSE BUDGET (STRICT)
- This section overrides later DEFAULT RESPONSE STRUCTURE and IRAC instructions when they conflict.
- Answer in Georgian unless the user asks otherwise.
- Keep the answer focused. Use only the legal issues that matter to the question.
- Do not list every retrieved source. Cite the best 5-8 sources unless the user explicitly asks for full research.
- Start with a direct conclusion, then analysis. Avoid repeating the facts.
- When the result depends on timing, amount, procedural stage, written request, expiry, or another boundary fact, state that boundary explicitly.
INST;
        }

        return <<<INST
RESPONSE BUDGET (STRICT)
- This section overrides every later DEFAULT RESPONSE STRUCTURE, ACTIVE MODE IRAC, and issue-spotter instruction when they conflict.
- Answer in Georgian unless the user asks otherwise.
- Ordinary legal Q&A must be short: about 6-10 concise sentences total, unless the user asks for a memo.
- Start exactly with "პირდაპირი პასუხი:" in 2-4 sentences. Do not start with "✅ დასკვნა და შეჯამება".
- Then add "წესი და გამოყენება:" with up to 3 bullets.
- Add "სიფრთხილე:" only if a missing fact or boundary changes the result.
- Do not use long IRAC headings for ordinary questions.
- Never produce numbered issue cards like "[1] ... Issue: Rule: Cases: Apply: Conclude:" for ordinary legal Q&A.
- Analyze only the boundary asked by the user. Do not add side issues such as ownership, eviction, damages, or full merits unless they are necessary to answer the asked boundary.
- Do not list every retrieved source. Use only the best 3-5 sources in the answer.
- Avoid categorical outcomes when a boundary fact matters. Prefer "თუ..." / "მაგრამ თუ..." rather than an unconditional conclusion.
INST;
    }

    private function buildDomainContextBlock(?TriageResult $triage): string
    {
        if (!$triage || $triage->isChatOnly()) {
            return '';
        }

        $caseTypeLabel = match ($triage->caseType) {
            'civil'          => 'სამოქალაქო სამართალი',
            'criminal'       => 'სისხლის სამართალი',
            'administrative' => 'ადმინისტრაციული სამართალი',
            default          => null,
        };

        $lines = ["────────────────────────\n🔬 LEGAL DOMAIN CONTEXT (automated triage)\n────────────────────────"];

        if ($caseTypeLabel) {
            $lines[] = "სამართლის ტიპი: **{$caseTypeLabel}**";
            if ($triage->caseType === 'criminal') {
                $lines[] = "⚠️ სისხლის სამართლის საქმე — სამოქალაქო ან ადმინისტრაციული სამართლის ნორმები არ გამოიყენო";
            } elseif ($triage->caseType === 'civil') {
                $lines[] = "⚠️ სამოქალაქო სამართლის საქმე — სისხლის სამართლის ნორმები არ გამოიყენო";
            }
        }

        if (!empty($triage->domains)) {
            $lines[] = "სფეროები: " . implode(', ', $triage->domains);
        }

        if ($triage->isComplex) {
            $lines[] = "კომპლექსური საკითხი — multi-IRAC სტრუქტურა სავალდებულოა";
        }

        if ($triage->temporalYear !== null) {
            $lines[] = "დროის კონტექსტი: კითხვა ეხება **{$triage->temporalYear} წელს** — ნორმები ფილტრირებულია ამ წლის მიხედვით";
        } else {
            $lines[] = "დროის კონტექსტი: კითხვაში კონკრეტული წელი ვერ მოიძებნა — ვარაუდობს მიმდინარე წელი (" . date('Y') . ")";
        }

        $lines[] = "────────────────────────";

        return implode("\n", $lines);
    }

    private function buildStatutoryRulesBlock(?TriageResult $triage, string $userQuestion = ''): string
    {
        if (!$triage || $triage->isChatOnly()) {
            return '';
        }

        $rules = [];

        $domains  = $triage->domains ?? [];
        $caseType = $triage->caseType ?? '';

        $hasCivilProc = in_array('civil_procedure', $domains, true)
            || in_array('procedure', $domains, true)
            || $caseType === 'civil';
        $hasCivilLaw  = in_array('civil_law', $domains)       || $caseType === 'civil';
        $hasCriminal  = in_array('criminal', $domains)        || $caseType === 'criminal';
        $hasAdmin     = in_array('administrative', $domains)  || $caseType === 'administrative';
        $hasLabor     = in_array('labor', $domains);

        if ($hasCivilProc) {
            $proceduralGuidanceLines = $this->consequenceTaxonomy->promptGuidanceLinesForQuestion(
                $userQuestion . "\n" . ($triage->searchQuery ?? ''),
                $triage,
                'procedural_outcome.',
            );
            $proceduralGuidance = implode("\n", array_map(fn (string $line) => "• {$line}", $proceduralGuidanceLines));
            if ($proceduralGuidance === '') {
                $proceduralGuidance = '• საპროცესო შედეგები განსაზღვრე მოქმედი საპროცესო ნორმით და მოძიებული წყაროებით.';
            }

            $rules[] = <<<RULES
📋 სამოქალაქო საპროცესო სამართლი — ძირითადი ზღვრები (სსკ):
{$proceduralGuidance}
RULES;
        }

        // Civil law substantive rules intentionally omitted — SemanticArticleRetriever
        // fetches the authoritative matsne_chunks_v2 articles for every specific question.
        // Hardcoding article facts here does not scale and risks becoming stale.

        if ($hasCriminal) {
            $rules[] = <<<RULES
📋 სისხლის სამართალი — ძირითადი პრინციპები:
• უდანაშაულობის პრეზ.: ბრალდ. უდანაშ-ია, სანამ არ დამტ. (სსსკ მე-3 მუხ.)
• in dubio pro reo: ეჭვი — ბრალდ. სასარგ.
• მტკ. ტვირთი: პროკ.-ზე (ბრალდ. ვალ. დაამტ.)
• ნასამართლობა: გამამტ. განაჩ. → ნასამ. ინახება (სსსკ 308)
RULES;
        }

        if ($hasAdmin) {
            $rules[] = <<<RULES
📋 ადმინისტრაციული სამართალი — ძირითადი ვადები (ზაკ/სასამ. კოდ.):
• ადმ. საჩ. ვადა: ადმ. ორგ-ის შეს. შეტყ-დან 1 თვე (ზოგ. ადმ. კოდ. 177)
• სასამართლო გასაჩ. ვადა: 3 თვე (ადმ. სამ. კოდ. მე-22)
• ადმ. ორგ. ვალ.: ახსნ-განმ. ვადა — 10 სამ. დღე
RULES;
        }

        if ($hasLabor) {
            $rules[] = <<<RULES
📋 შრომის სამართალი — ძირითადი ვადები:
• სამ.-დან გათ.-ის გასაჩ. ვადა: 30 კალ. დღე (შრ. კოდ. 38)
• შრ. ხელშ. ბათ-ობა: სსკ-ის ნორმები ვრცელდება
• კომპ.-ის მოთხ. ვადა: ხანდაზმ. 3 წ. (სსკ 128)
RULES;
        }

        if (empty($rules)) {
            return '';
        }

        $body = implode("\n\n", $rules);

        return <<<BLOCK
────────────────────────
⚖️ STATUTORY RULES — ავტ. ჩართული ამ domain-ისთვის
────────────────────────
{$body}
⚠️ ეს წესები ყოველთვის შეამოწმე — კითხვაში ხსენებული ზღვრები, ვადები და ნორმები სავალდ. გაანალიზე.
────────────────────────
BLOCK;
    }

    private function buildModeInstruction(string $mode): string
    {
        return match ($mode) {
            'chat' => <<<INST
ეს მეგობრული, საინფორმაციო საუბარია — სამართლებრივი ანალიზი არ გჭირდება.

✅ უპასუხე ბუნებრივად, მოკლედ და სასარგებლოდ
✅ თუ მომხმარებელი კონკრეტულ სამართლებრივ კითხვას სვამს — შეახსენე, რომ ის კითხვა დასვას და ბაზაში მოვძებნი
❌ IRAC სტრუქტურა ნუ გამოიყენე
❌ case-ები ნუ გამოიგონე
❌ სამართლებრივი ანალიზის სექციები ნუ ჩასვი
INST,

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
   - 📕 კანონი/ნორმა: [CONTEXT-ის matsne კანონი + მუხლი; არ დატოვო მხოლოდ კოდექსის სახელად და არ დაწერო „მუხლები მოძიებული არ არის“, თუ CONTEXT-ში მუხლებია]
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

    private function contextDecisionLimit(string $mode): int
    {
        $isComplexMode = in_array($mode, ['advocate', 'compare', 'summarize', 'find'], true);
        $configKey = $isComplexMode ? 'openai.max_context_decisions_complex' : 'openai.max_context_decisions_default';

        return $this->contextConfigLimit($configKey, $isComplexMode ? 5 : 3);
    }

    private function matsneContextLimit(string $mode, ?TriageResult $triage): int
    {
        $isComplex = in_array($mode, ['advocate', 'compare', 'summarize', 'find'], true)
            || ($triage?->complexityLevel === 'full')
            || (($triage?->issueList->issueCount ?? 0) >= 4);

        return $isComplex
            ? $this->contextConfigLimit('openai.max_matsne_context_results_complex', 10)
            : $this->contextConfigLimit('openai.max_matsne_context_results', 4);
    }

    private function contextConfigLimit(string $key, int $fallback): int
    {
        return max(0, (int) config($key, $fallback));
    }

    // ── Context Block ─────────────────────────────────────────────────────────

    private function buildContextBlock(array $decisions, int $totalFound = 0, string $mode = 'explain', array $lawResults = [], array $echrResults = [], array $matsneResults = [], array $euResults = [], array $germanResults = [], array $constCourtResults = [], ?TriageResult $triage = null): string
    {
        $parts = [];

        $decisions         = array_slice($decisions, 0, $this->contextDecisionLimit($mode));
        $lawResults        = array_slice($lawResults, 0, $this->contextConfigLimit('openai.max_law_context_results', 4));
        $matsneResults     = array_slice($matsneResults, 0, $this->matsneContextLimit($mode, $triage));
        $echrResults       = array_slice($echrResults, 0, $this->contextConfigLimit('openai.max_echr_context_results', 2));
        $euResults         = array_slice($euResults, 0, $this->contextConfigLimit('openai.max_eu_context_results', 2));
        $germanResults     = array_slice($germanResults, 0, $this->contextConfigLimit('openai.max_german_context_results', 2));
        $constCourtResults = array_slice($constCourtResults, 0, $this->contextConfigLimit('openai.max_const_court_context_results', 2));

        // ── Verified sources whitelist — LLM-ს ეუბნება რომელი ნომრები "აქვს" ──
        $verifiedBlock = $this->citationVerifier->buildVerifiedSourcesBlock($decisions, $matsneResults);
        if (!empty($verifiedBlock)) {
            $parts[] = $verifiedBlock;
        }

        // ── Law articles block ────────────────────────────────────────────────
        if (!empty($lawResults)) {
            $lawBlock = ["RETRIEVED LEGISLATION:\n"];
            $authority = LegalAuthorityTaxonomyService::legislation();
            foreach ($lawResults as $i => $law) {
                /** @var LawResult $law */
                $num          = $i + 1;
                $articleLabel = $law->articleNum
                    ? "{$law->articleNum}" . ($law->articleTitle ? " — {$law->articleTitle}" : '')
                    : '';

                $lawBlock[] = <<<BLOCK
--- LAW #{$num} ---
AUTHORITY_STATUS: {$authority['authority_status']} | Binding: yes
AUTHORITY_CAVEAT: {$authority['authority_caveat']}
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
            $parts[] = $this->buildMatsneBlock($matsneResults);
        }

        // ── EU documents block ────────────────────────────────────────────────
        if (!empty($euResults)) {
            $euBlock = ["RETRIEVED EU DOCUMENTS (EU Legislation & CJEU Case Law):\n"];
            $authority = LegalAuthorityTaxonomyService::comparativeNonBinding('EU/CJEU');
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
AUTHORITY_STATUS: {$authority['authority_status']} | Binding: no
AUTHORITY_CAVEAT: {$authority['authority_caveat']}
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
            $authority = LegalAuthorityTaxonomyService::comparativeNonBinding('German court practice');
            foreach ($germanResults as $i => $doc) {
                $num   = $i + 1;
                $court = $doc['court_name'] ?? 'N/A';
                $year  = $doc['date_year']  ?? 'N/A';
                $level = $doc['level_of_appeal'] ?? '';

                $germanBlock[] = <<<BLOCK
--- GERMAN CASE #{$num} ---
AUTHORITY_STATUS: {$authority['authority_status']} | Binding: no
AUTHORITY_CAVEAT: {$authority['authority_caveat']}
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
            $authority = LegalAuthorityTaxonomyService::constitutionalCourt();
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
AUTHORITY_STATUS: {$authority['authority_status']} | Binding: yes
AUTHORITY_CAVEAT: {$authority['authority_caveat']}
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
            $authority = LegalAuthorityTaxonomyService::echr();
            foreach ($echrResults as $i => $echr) {
                $num         = $i + 1;
                $title       = $echr instanceof EchrResult ? $echr->title : ($echr['title'] ?? '');
                $application = $echr instanceof EchrResult ? $echr->applicationNumber : ($echr['application_number'] ?? null);
                $date        = $echr instanceof EchrResult ? $echr->judgmentDate : ($echr['judgment_date'] ?? null);
                $type        = $echr instanceof EchrResult ? $echr->documentType : ($echr['document_type'] ?? null);
                $articleList = $echr instanceof EchrResult ? $echr->echrArticles : ($echr['echr_articles'] ?? []);
                $importanceValue = $echr instanceof EchrResult ? $echr->importance : ($echr['importance'] ?? null);
                $url         = $echr instanceof EchrResult ? $echr->sourceUrl : ($echr['url'] ?? $echr['source_url'] ?? '');
                $excerpt     = $echr instanceof EchrResult ? $echr->excerpt : ($echr['excerpt'] ?? '');
                $articles    = !empty($articleList) ? implode(', ', $articleList) : 'N/A';
                $importance  = match ($importanceValue) {
                    1 => 'Key Case',
                    2 => 'High Importance',
                    3 => 'Medium',
                    4 => 'Low',
                    default => 'Unknown',
                };

                $echrBlock[] = <<<BLOCK
--- ECHR CASE #{$num} ---
AUTHORITY_STATUS: {$authority['authority_status']} | Binding: yes
AUTHORITY_CAVEAT: {$authority['authority_caveat']}
Title: {$title}
Application No: {$application}
Date: {$date}
Type: {$type} | Importance: {$importance}
Articles: {$articles}
URL: {$url}

EXCERPT:
{$excerpt}
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

        $primaryCount = count(array_filter(
            $decisions,
            fn (array $d) => ($d['answer_role'] ?? null) === 'primary',
        ));

        $blocks = ["STATUS: FOUND — {$count} გადაწყვეტილება{$totalNote}\n"];
        if ($primaryCount === 0) {
            $blocks[] = <<<GUARD
COURT AUTHORITY GUARD:
No retrieved Georgian court decision is marked PRIMARY AUTHORITY for this question.
Do NOT write that "court practice confirms/recognizes" the rule.
Do NOT create a "court practice confirms" style section from SUPPORTING ANALOGY cases.
If you mention any retrieved decision, label it only as weak/supporting analogy.
Use this wording if needed: "პირდაპირი შესაბამისი სასამართლო პრაქტიკა მოძიებულ წყაროებში ვერ მოიძებნა; ქვემოთ მოყვანილი საქმეები მხოლოდ დამხმარე ანალოგიაა."
For the main answer, rely on retrieved legislation and explicitly say direct court practice was not found in the retrieved sources.
GUARD;
        }

        foreach ($decisions as $i => $d) {
            $num  = $i + 1;
            $role = $d['answer_role'] ?? ($i < 2 ? 'primary' : 'supporting');
            $roleLabel = $role === 'primary' ? 'PRIMARY AUTHORITY' : 'SUPPORTING ANALOGY';
            $authorityStatus = $d['authority_status'] ?? 'persuasive_supreme';
            $authorityBinding = !empty($d['authority_binding']) ? 'yes' : 'no';
            $authorityCaveat = $d['authority_caveat']
                ?? 'Do not call this source mandatory precedent unless AUTHORITY_STATUS is binding_full_chamber.';
            $authorityStatusLabel = $d['authority_status_label'] ?? $authorityStatus;
            $roleInstruction = $d['usage_instruction'] ?? (
                $role === 'primary'
                    ? 'Use as a main authority for the legal answer.'
                    : 'Use only as supporting analogous practice if it confirms the same legal issue.'
            );
            $mandatoryCitation = $role === 'primary' && !empty($d['case_num'])
                ? "\nMANDATORY_CITATION: final answer must mention this exact case_num: {$d['case_num']}"
                : '';
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
                $authorityNote = "\nAUTHORITY_SCORE: court={$auth['court_score']} year={$auth['year_score']} joint={$auth['joint_bonus']} total={$auth['total']}";
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
            $semanticSection = '';
            if (!empty($d['semantic_relevance'])) {
                $sem = $d['semantic_relevance'];
                $semanticSection = sprintf(
                    "\nSTRUCTURED RELEVANCE: %s/100 | confidence=%s | legal_issue=%s | holding=%s | facts=%s | articles=%s | procedure=%s | retrieval_rank=%s | role=%s | reason=%s",
                    $d['semantic_relevance_score'] ?? 'N/A',
                    $sem['confidence'] ?? 'N/A',
                    $sem['legal_issue_match'] ?? 'N/A',
                    $sem['holding_match'] ?? 'N/A',
                    $sem['fact_pattern_match'] ?? 'N/A',
                    $sem['article_match'] ?? 'N/A',
                    $sem['procedural_match'] ?? 'N/A',
                    $sem['retrieval_rank_signal'] ?? 'N/A',
                    $roleLabel,
                    $d['ranking_explanation'] ?? 'N/A',
                );
            }

            $blocks[] = <<<BLOCK
--- DECISION #{$num} ({$roleLabel}) ---
{$meta}
USE ROLE: {$roleLabel} — {$roleInstruction}
AUTHORITY_STATUS: {$authorityStatus} | {$authorityStatusLabel} | Binding: {$authorityBinding}
AUTHORITY_CAVEAT: {$authorityCaveat}
Relevance: {$d['relevance_score']}{$combinedScore} | Chunks: {$d['chunk_count']} | Mode: {$status}{$mandatoryCitation}{$semanticSection}{$authorityNote}{$outlierNote}{$trendNote}{$qualityNote}{$keyFactsSection}{$evidenceSection}

TEXT:
{$textToSend}
--- END DECISION #{$num} ---
BLOCK;
        }

        $parts[] = "RETRIEVED COURT DECISIONS:\n\n" . implode("\n\n", $blocks);

        return implode("\n\n────────────────────────\n\n", $parts);
    }

    /**
     * Formats matsne results into a structured context block.
     *
     * Article-sourced docs (article_detector / concept_detector) are grouped by law
     * and labeled clearly so the AI knows exactly which article it is reading.
     * Semantic docs fall back to the generic format.
     */
    private function buildMatsneBlock(array $matsneResults): string
    {
        $authority = LegalAuthorityTaxonomyService::legislation();

        // Separate article-specific results from semantic results
        $articleDocs  = [];
        $semanticDocs = [];

        foreach ($matsneResults as $doc) {
            $source = $doc['_source'] ?? null;
            if (in_array($source, ['article_detector', 'concept_detector'], true)) {
                $articleDocs[] = $doc;
            } else {
                $semanticDocs[] = $doc;
            }
        }

        $sections = [];

        // ── Article-specific: grouped by matsne_id ────────────────────────────
        if (!empty($articleDocs)) {
            $byLaw = [];
            foreach ($articleDocs as $doc) {
                $byLaw[$doc['matsne_id']][] = $doc;
            }

            $lawSections = [];
            foreach ($byLaw as $matsneId => $docs) {
                $firstDoc  = $docs[0];
                $title     = $firstDoc['title'];
                $active    = $firstDoc['is_active'] ? 'მოქმედი' : 'ძველი ვერსია';
                $years     = $firstDoc['effective_from_year']
                    ? ($firstDoc['effective_from_year'] . ($firstDoc['effective_to_year'] ? '–' . $firstDoc['effective_to_year'] : '–დღეს'))
                    : '';
                $url       = $firstDoc['url'];
                $yearsStr  = $years ? " | {$years}" : '';

                // Collect concept stems that triggered this law
                $stems = array_unique(array_filter(array_column($docs, '_concept_stem')));
                $stemNote = !empty($stems) ? ' (detected: "' . implode('", "', $stems) . '")' : '';

                // Article sub-sections
                $articleLines = [];
                foreach ($docs as $doc) {
                    $artNum    = $doc['_article_num'] ?? null;
                    $source    = $doc['_source'];
                    $sourceTag = $source === 'article_detector' ? '📌 explicit citation' : '💡 concept-injected';
                    $artHeader = $artNum ? "მუხლი {$artNum}" : 'სექცია';

                    $articleLines[] = <<<ART

{$artHeader} [{$sourceTag}]:
{$doc['excerpt']}
ART;
                }

                $articlesBlock = implode("\n" . str_repeat('─', 40), $articleLines);

                $lawSections[] = <<<LAW
╔══ {$title}{$yearsStr} | {$active} ══╗
AUTHORITY_STATUS: {$authority['authority_status']} | Binding: yes
AUTHORITY_CAVEAT: {$authority['authority_caveat']}
URL: {$url}{$stemNote}
{$articlesBlock}
╚══ END: {$title} ══╝
LAW;
            }

            $sections[] = "RETRIEVED LEGISLATION — SPECIFIC ARTICLES:\n\n" . implode("\n\n", $lawSections);
        }

        // ── Semantic results: standard format ─────────────────────────────────
        if (!empty($semanticDocs)) {
            $semanticLines = ["RETRIEVED MATSNE DOCUMENTS (semantic search):\n"];
            foreach ($semanticDocs as $i => $doc) {
                $num     = $i + 1;
                $active  = $doc['is_active'] ? 'მოქმედი' : 'ძველი ვერსია';
                $docType = $doc['doc_type'] ?? 'N/A';
                $issuer  = $doc['issuer']   ?? 'N/A';
                $years   = $doc['effective_from_year']
                    ? ($doc['effective_from_year'] . ($doc['effective_to_year'] ? '–' . $doc['effective_to_year'] : '–დღეს'))
                    : 'N/A';

                $semanticLines[] = <<<BLOCK
--- MATSNE DOC #{$num} ---
AUTHORITY_STATUS: {$authority['authority_status']} | Binding: yes
AUTHORITY_CAVEAT: {$authority['authority_caveat']}
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
            $sections[] = implode("\n\n", $semanticLines);
        }

        return implode("\n\n────────────────────────\n\n", $sections);
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
        $role     = $d['answer_role'] ?? null;

        if ($role === 'supporting') {
            $limit = $mode === 'find' ? 1200 : 2000;
            $text = mb_substr(!empty($excerpt) ? $excerpt : $fullText, 0, $limit);
            return [$text, 'SUPPORTING-COMPACT'];
        }

        if ($mode === 'find' || $totalDecisions > 5) {
            $text = mb_substr(!empty($excerpt) ? $excerpt : $fullText, 0, 1200);
            return [$text, 'COMPACT'];
        }

        if ($totalDecisions > 3 && $mode !== 'summarize') {
            $limit = min((int) config('openai.max_chars_per_decision', 7000), 7000);
            $text = mb_substr(!empty($excerpt) ? $excerpt : $fullText, 0, $limit);
            return [$text, 'PRIMARY-CONTEXT'];
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
        array           $sources = [],
        ?IssueList      $issueList = null,
        ?TriageResult   $triage = null,
        array           $extractedRules = [],
    ): \Generator {
        set_time_limit(0);
        $model = $this->modelForRequest($userQuestion, $mode, $triage, $issueList);

        Log::info('OpenAILegalAnswerService: streamTokens called', [
            'model' => $model,
            'default_model' => $this->model,
            'mode' => $mode,
            'complexity_level' => $triage?->complexityLevel,
            'complexity_score' => $triage?->complexityScore,
        ]);

        $systemPrompt = $this->buildSystemPrompt($mode, $confidence, $sources, !empty($matsneResults), $issueList, $userQuestion, $triage);
        $rulesBlock   = $this->ruleExtractor->buildPromptBlock($extractedRules);
        $remedyBlock  = $this->remedyGuard->buildPromptBlock($userQuestion, $matsneResults, $decisions, $triage);
        $contextBlock = implode("\n\n", array_filter([
            $rulesBlock,
            $remedyBlock,
            $this->buildContextBlock($decisions, $totalFound, $mode, $lawResults, $echrResults, $matsneResults, $euResults, $germanResults, $constCourtResults, $triage),
        ]));
        $messages     = $this->buildMessages($systemPrompt, $contextBlock, $historyMessages, $userQuestion);

        $client = new GuzzleClient();

        $response = $client->post("{$this->baseUrl}/chat/completions", [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'text/event-stream',
            ],
            'json' => [
                'model'       => $model,
                'messages'    => $messages,
                'max_tokens'  => $this->maxTokens,
                'temperature' => $this->temperature,
                'stream'      => true,
                'stream_options' => [
                    'include_usage' => true,
                ],
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
                if (isset($json['usage']) && is_array($json['usage'])) {
                    $this->usageTracker->recordChat('answer_stream', $model, $json['usage']);
                    continue;
                }

                $token = $json['choices'][0]['delta']['content'] ?? '';

                if ($token !== '') {
                    yield $token;
                }
            }
        }
    }

    private function modelForRequest(
        string $userQuestion,
        string $mode,
        ?TriageResult $triage = null,
        ?IssueList $issueList = null,
    ): string {
        $defaultModel = (string) config('openai.chat_model', $this->model);

        if (!(bool) config('openai.dynamic_chat_model_enabled', true)) {
            return $defaultModel;
        }

        $complexModel = (string) config('openai.complex_chat_model', 'gpt-4.1');
        if ($complexModel === '') {
            return $defaultModel;
        }

        return $this->shouldUseComplexAnswerModel($userQuestion, $mode, $triage, $issueList)
            ? $complexModel
            : $defaultModel;
    }

    private function shouldUseComplexAnswerModel(
        string $userQuestion,
        string $mode,
        ?TriageResult $triage = null,
        ?IssueList $issueList = null,
    ): bool {
        $scoreThreshold = (int) config('openai.complex_model_min_score', 61);
        $charThreshold = (int) config('openai.complex_model_min_chars', 700);

        if (($triage?->complexityLevel) === 'full') {
            return true;
        }

        if (($triage?->complexityScore ?? 0) >= $scoreThreshold) {
            return true;
        }

        if (mb_strlen(trim($userQuestion)) >= $charThreshold) {
            return true;
        }

        if (($issueList?->isComplex ?? false) || ($issueList?->issueCount ?? 0) >= 3) {
            return true;
        }

        return in_array($mode, ['advocate', 'compare'], true)
            && mb_strlen(trim($userQuestion)) >= 300;
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
