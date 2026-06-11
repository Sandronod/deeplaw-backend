# RAG / Legal QA Roadmap

Last updated: 2026-06-11

This note is the handoff context for continuing work on the Georgian Legal AI Chat project in a new session.

Current focus: legal question answering, source retrieval, norm retrieval, court decision matching, answer grounding, evaluator quality, and latency. Security, chat ownership, and account/permission topics are intentionally out of scope for this phase.

## Current Status - 2026-06-11

The immediate focus has moved from one-off prompt fixes to reusable quality controls.

Implemented today:

- Rule atom prompt guidance is now registry-driven:
  - `summary_lines` and `prompt_guard_lines` live in `config/legal_consequence_rules.php`.
  - `OpenAILegalAnswerService` no longer hardcodes the magistrate/Article 365 guard.
  - Procedural prompt guidance is selected by matching rule atom triggers and category.
- Civil procedure atoms now cover:
  - magistrate claim value boundary (`claim_value <= 50000`, equal included)
  - counterclaim subject-matter jurisdiction guard
  - counterclaim preparatory-stage guard
- Added a deterministic answer correction gate:
  - first generated answer is post-processed and validated
  - if validator returns `fail` or any `high` flag, the system makes one correction retry
  - retry uses the same retrieved sources and a focused correction prompt
  - final DB meta includes `answer_correction`
  - SSE `done.content` returns the final corrected content, so the frontend replaces streamed draft text
- LLM-as-Judge remains async/optional and is not used as a blocking production gate.

Still not implemented:

- automatic candidate atom generation from failed answers
- clustering repeated failures into an atom backlog
- human approval workflow for draft atoms
- DB-backed/versioned rule atom registry

## Where We Are

The system is now meaningfully better than the initial state for complex legal Q&A.

Recent benchmark-style test case:

- Task: hidden defect + immoral transaction in a real estate sale.
- Earlier problem: answer mixed up `defect -> invalidity`, overused weak court practice, and evaluator gave an unfair `3.6/10`.
- Current state: evaluator now gives around `7.7/10`; human review is around `7.8-8.2/10`.
- Main remaining issues are refinement-level, not pipeline failure: more precise remedy terminology, better use of article 55 when grounded, and softer wording when only supporting/analogical case law exists.

## What We Improved

### Retrieval And Court Decision Use

- Added stronger focused query handling for large fact patterns and full casus questions.
- Improved court ranking so generic extracted text does not dominate the real legal issue.
- Added decision role handling:
  - `PRIMARY AUTHORITY` for strong matches.
  - `SUPPORTING ANALOGY` for weak/indirect matches.
- Weak/supporting cases are no longer supposed to be cited as direct authority.
- The answer prompt now warns the model not to say "court practice confirms" when no primary authority was retrieved.

### Matsne / Norm Retrieval

- Article detection now infers core civil-law articles from concepts, not only explicit article numbers.
- For the hidden defect / immoral transaction pattern, relevant articles include:
  - `54`, `55`
  - `81`, `84`
  - `490`, `491`, `492`, `494`, `495`, `497`
  - `129`, `130`
- The `55` article was added for price disproportionality / obvious mismatch between performance and counter-performance.
- Concept-injected articles are capped more tightly than explicit user-requested articles to avoid unnecessary latency and prompt bloat.

### Remedy / Outcome Guarding

Added `LegalRemedyGuardService`.

Purpose: force the model to separate legal basis from legal consequence.

Core distinctions now guarded:

- `nullity / ბათილობა`
- `avoidance / შეცილება`
- `termination / მოშლა`
- `price reduction / ფასის შემცირება`
- `cure or replacement / ნაკლის გამოსწორება ან ნივთის შეცვლა`
- `damages / ზიანი`
- `notice or preclusion / პრეტენზია, შეტყობინება, უფლების დაკარგვა`
- `limitation / ხანდაზმულობა`

Important example:

- Wrong: `ნაკლი -> ბათილობა`
- Correct: `ნაკლი -> მოშლა / ფასის შემცირება / გამოსწორება / ზიანი`
- Invalidity can still be discussed separately through fraud, article 54, or another independent basis.

### Answer Validation

Expanded `AnswerValidatorService`.

It now checks for:

- unsupported articles
- unsupported legal numbers / deadlines
- unsupported court-practice claims
- weak/supporting case used as primary authority
- unsupported remedy outcome
- defect remedy turned into invalidity
- defect notice/preclusion incorrectly called challenge period

Useful flags:

- `defect_nullity_conflation`
- `defect_notice_as_challenge_period`
- `weak_case_authority_claim`
- `overstated_weak_case_law_claim`
- `unsupported_legal_remedy`

### Deterministic Post-Processing

Added `AnswerPostProcessorService`.

This is intentionally cheap: no OpenAI call, no embeddings, no database query.

It performs safe final-pass cleanup:

- `შეცილების ვადა დაფარული ნაკლის გამო`
  becomes
  `პრეტენზიის/შეტყობინების ვადა დაფარული ნაკლის გამო`
- If article `55` is retrieved but missing from the price-disproportion analysis, it can add a short grounded line.
- If there is no primary domestic authority, it softens overly broad court-practice wording.

Streaming behavior:

- The frontend receives tokens normally.
- At `done`, the backend can return final corrected `content`.
- The frontend replaces the streamed text with final corrected content.
- This does not add visible latency.

### Evaluator / LLM-As-Judge

The earlier evaluator score of `3.6/10` was mostly evaluator error.

Root causes fixed:

- Judge previously received only partial answer text.
- Judge previously did not receive Constitutional Court / ECHR / EU / German sources.
- Judge previously received Matsne titles without enough article/source context.
- UI label was misleading:
  - old: `ჰალუცინაცია (10=კი)`
  - fixed: `ჰალუცინაციის არქონა`

Current judge prompt:

- Sends up to 12k chars of the answer.
- Sends source whitelist with excerpts.
- Includes domestic court, Matsne, Constitutional Court, ECHR, EU, and German sources when available.
- Explicitly tells judge not to punish supporting/analogical cases merely because they are not primary precedent.

Recommendation:

- Keep evaluator for QA.
- Do not treat it as absolute truth.
- In production, make it optional, async, or button-triggered.

### Frontend Changes

- Evaluation label fixed to `ჰალუცინაციის არქონა`.
- SSE `done` payload can include final corrected `content`.
- Chat service replaces streamed content with final backend content if present.

## Tests / Verification

Latest verified commands (2026-06-11):

```powershell
& 'C:\Users\NODO\.config\herd\bin\php84.bat' artisan test --testsuite=Unit
```

Result:

```text
99 passed (443 assertions)
```

Frontend:

```powershell
npm run build
```

Result: build passed. Existing Angular warning remains:

```text
Application bundle generation complete.
```

## Production Notes

Migrations already discussed:

- `2026_06_08_000072_repair_canonical_matsne_status`
- `2026_06_08_000073_activate_current_tax_code`

These do not create schema-heavy changes; they repair/activate canonical Matsne document status and current tax code status. For production, run migrations rather than overwriting database tables.

Important env/model variables:

```env
OPENAI_CHAT_MODEL=gpt-4.1
OPENAI_EXTRACTION_MODEL=gpt-4.1-mini
OPENAI_JUDGE_MODEL=o4-mini
EVAL_JUDGE_ENABLED=false # recommended default for production unless QA mode
```

If live judge evaluation is enabled, it costs extra and adds post-answer latency. It should be optional for production.

## What Is Still Needed

### 1. Legal Outcome Taxonomy

Highest priority.

Goal: make remedy/outcome handling universal instead of patching individual phrases.

Build a structured taxonomy such as:

- `invalidity`
- `avoidance`
- `termination`
- `notice_preclusion`
- `limitation`
- `damages`
- `price_reduction`
- `cure_or_replacement`
- `administrative_appeal`
- `procedural_deadline`

Then map retrieved articles and extracted rules into this taxonomy.

Expected benefit:

- Less prompt bloat.
- Less one-off patching.
- Better universal control over legal consequences.

### 2. Gold Test Set

Create 30-50 controlled scenarios with expected references.

Include:

- simple article questions
- long civil casus
- administrative appeal deadlines
- tax-code questions
- labor termination questions
- court-practice search questions
- weak/analogical case scenarios
- hidden-defect / fraud / price-disproportion scenarios

For each scenario store:

- question
- expected articles
- expected case numbers if any
- forbidden articles/cases if important
- expected remedy categories
- notes on acceptable answer shape

Then run benchmark after every retrieval/prompt change.

### 3. Latency Strategy

Keep answer quality high without slowing all questions.

Recommended approach:

- Fast path for exact/simple norm questions.
- Full path only for complex casus.
- Skip semantic article supplement when enough direct article docs are already found.
- Keep evaluator async/optional.
- Avoid adding more LLM calls for deterministic legal terminology fixes.
- Track stage timings in `meta.pipeline_ms`.

### 4. Better Court Rerank For Difficult Casus

Current primary/supporting logic improved, but hard casus can still retrieve only weak analogies.

Next work:

- strengthen legal-issue matching
- use structured issue list in reranker
- prefer same remedy/outcome category
- do not promote generic article-54 cases unless factual/legal issue is close

### 5. Internal QA Display

For development, expose:

- answer validator flags
- answer post-process changes
- primary/supporting case roles
- evaluator raw issues
- retrieval source counts

For production, keep these hidden or behind debug mode.

## Design Principle Going Forward

Do not solve every legal mistake by adding another long prompt instruction.

Preferred order:

1. Retrieve the right source.
2. Extract/source-map the legal outcome.
3. Force the answer to use source-grounded outcome categories.
4. Validate deterministically.
5. Use narrow post-processing only for repeated, safe wording fixes.
6. Use LLM-as-judge only as a secondary QA signal.

## Recommended Next Session Start

If continuing in a new Codex session, start with:

```text
Read AGENTS.md and RAG_ROADMAP.md. Continue from the Legal Outcome Taxonomy step.
```

Then inspect:

- `app/Services/AI/LegalRemedyGuardService.php`
- `app/Services/AI/AnswerValidatorService.php`
- `app/Services/AI/AnswerPostProcessorService.php`
- `app/Services/Matsne/ArticleDetectorService.php`
- `app/Services/Legal/LegalChatOrchestratorService.php`
- `tests/Unit/*Remedy*`
- `tests/Unit/AnswerValidatorServiceTest.php`
- `tests/Unit/AnswerPostProcessorServiceTest.php`
