# RAG / Legal QA Roadmap

Last updated: 2026-06-22

This note is the handoff context for continuing work on the Georgian Legal AI Chat project in a new session.

Current focus: legal question answering, source retrieval, norm retrieval, court decision matching, answer grounding, evaluator quality, and latency. Security, chat ownership, and account/permission topics are intentionally out of scope for this phase.

## Current Status - 2026-06-22

Latest large-casus hardening:

- Added the first config-backed Legal Issue -> Norm Map:
  - `config/legal_issue_norms.php` now maps reusable legal issues to triggers, required law/article refs, must-discuss points, forbidden shortcuts, and boundary rules
  - `LegalIssueNormMapService` matches questions/facts/domains against this registry
  - `LegalNormCoveragePlannerService` converts matched issues into ArticleDetector-compatible Matsne article refs
  - `ArticleDetectorService` now merges registry-planned article refs with the older hardcoded concept fallback, so existing behavior is preserved while new norm routing can grow from config
  - Initial mapped issues include magistrate claim value, counterclaim subject-matter guard, real-estate registration, mortgage priority/enforcement, insolvency creditor status, inheritance/marital property, personal-data incidents, administrative review, labor termination/non-compete/material liability, damages, penalty reduction, criminal preclusion, proof burden, and procedural joinder
- German comparative source routing was fixed:
  - selecting the German source in the UI now prevents domestic norm-only routing from suppressing German retrieval
  - explicit German-practice signals in the question (for example "გერმანიის სასამართლო პრაქტიკა" / "გერმანული გადაწყვეტილებები") now auto-enable German retrieval even when default sources are only court + Matsne
  - DB smoke check confirmed German data is present: `german_cases` ≈ 242k and `german_chunks_de` ≈ 7k embedded chunks
- Source routing now follows the same override rule for extra databases:
  - selected or explicitly requested ECHR/EU/German/Constitutional Court sources are no longer suppressed by domestic norm-only routing
  - explicit EU and Constitutional Court signals now produce source-plan flags and unit coverage, matching the German fix
- Dynamic answer model routing is in place:
  - small/ordinary questions can use `gpt-4.1-mini`
  - full/large casus questions can use `gpt-4.1`
- Large-casus source grounding was tightened:
  - prompt now requires law + article when article-level Matsne context exists
  - correction prompt rejects source lines that only say a code name or "მუხლები მოძიებული არ არის"
  - validator flags `**წყარო:** შრომის კოდექსი` / `სამოქალაქო კოდექსი` style source lines when no article or special rule is given
  - validator flags personal-data answers that deny retrieved personal-data law articles
  - postprocessor replaces malformed personal-data source lines with clean law/article wording when articles are in context
  - postprocessor softens overbroad "administrative fine only on legal person" wording into a safer automatic-transfer/regress framework
- ArticleDetector now also infers administrative-review grounding articles when the casus mentions administrative decisions, appeals, judicial review, administrative fines, or the Personal Data Protection Service.
- For real-estate development / unfinished apartment sale casus patterns, ArticleDetector now infers:
  - Civil Code articles for real-estate registration, public registry presumption, apartment ownership, mortgage priority/enforcement, inheritance, and marital property
  - insolvency law articles for creditor claims and the creditors' register
  - Civil Procedure Code articles for criminal-judgment preclusion, proof burden, and procedural joinder
- Validator now flags high-risk omissions when an answer discusses registry ownership, mortgage, insolvency, inheritance, marital property, criminal preclusion, or joinder while omitting the retrieved article-level sources.
- Current Unit verification: `139 passed (607 assertions)`.

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
- Added source authority taxonomy:
  - domestic decisions now carry `authority_status`, `authority_binding`, and `authority_caveat`
  - ordinary Supreme/Appellate/Lower decisions are treated as persuasive, not automatically binding
  - Supreme full/joint chamber decisions are marked `binding_full_chamber`
  - Matsne/law sources are marked `binding_legislation`
  - Constitutional Court sources are marked `constitutional_binding_erga_omnes`
  - ECHR sources are marked `echr_interpretive_authority`
  - EU/German sources are marked `comparative_non_binding`
- Answer prompts and verified citation blocks now instruct the model to respect `AUTHORITY_STATUS`.
- `AnswerValidatorService` now flags a non-binding domestic case if the answer calls it a binding precedent.
- Criminal/procedural ECHR routing was strengthened:
  - Article 7 / nulla poena is now detected
  - detention, arrest, preventive measure, charge, judgment, and fair-trial signals can enable ECHR retrieval
  - ECHR can auto-run on a strong ECHR source plan even when the default UI source set is only court/matsne
- Matsne version filtering now uses a retrieved case year when the user asks about a specific case and did not state a separate temporal year.
- Chat message endpoints now use a named `chat-stream` throttle.
- Streaming UX now reports granular pipeline phases:
  - question analysis, legal issue triage, query normalization
  - case retrieval, law lookup, ECHR lookup, comparative lookup
  - reranking, authority check, context building, answer writing, validation, and finalization
  - frontend labels use concise Georgian phase text while keeping the elapsed timer visible
- Added a large-casus answer-quality fixture for public procurement + expropriation:
  - `tests/Fixtures/large_casus_public_procurement_expropriation.json`
  - `tests/Fixtures/large_casus_public_procurement_expropriation.md`
  - covers expected issue spotting, key facts, required answer points, and forbidden legal mistakes
- Strengthened large-casus source grounding:
  - added `LegalSourceCoverageGuardService` for issue-specific source expectations
  - large fact patterns now warn the answerer not to replace special laws with generic Civil Code / "general principles"
  - ArticleDetector now infers special grounding sources for non-compete, labor material liability, penalty reduction, damages, and personal-data incidents
  - personal-data law is available through the canonical Matsne resolver
  - validator now flags generic source placeholders and Civil Code article 55 misuse
  - full/complex casus answers can include a wider Matsne context budget
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
105 passed (458 assertions)
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
OPENAI_CHAT_MODEL=gpt-4.1-mini
OPENAI_COMPLEX_CHAT_MODEL=gpt-4.1
OPENAI_DYNAMIC_CHAT_MODEL_ENABLED=true
OPENAI_EXTRACTION_MODEL=gpt-4.1-mini
OPENAI_JUDGE_MODEL=o4-mini
MAX_MATSNE_CONTEXT_RESULTS=4
MAX_MATSNE_CONTEXT_RESULTS_COMPLEX=10
CHAT_STREAM_RATE_LIMIT_PER_MINUTE=6
CHAT_STREAM_IP_RATE_LIMIT_PER_MINUTE=30
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
