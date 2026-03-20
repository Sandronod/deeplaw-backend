# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Georgian Legal AI Chat — Laravel 12 backend + Angular 19 frontend. Performs hybrid vector + metadata search over Georgian court decisions (pgvector), generates grounded answers via OpenAI GPT-4.1, and streams them through a ChatGPT-like UI.

---

## Development Commands

### Backend (Laravel 12, PHP 8.2+)
PHP is installed via Laravel Herd. Use the full path:
```
"C:\Users\Nodari Karashvili\.config\herd\bin\php.bat" artisan <command>
```
Or via PowerShell:
```powershell
Set-Location 'D:\claude\ChatBot'
& 'C:\Users\Nodari Karashvili\.config\herd\bin\php.bat' artisan migrate
```

Key artisan commands:
- `artisan migrate` — run pending migrations
- `artisan config:clear` — clear config cache
- `artisan tinker` — REPL for debugging

### Frontend (Angular 19)
```bash
cd frontend

# Start dev server (port 4200, proxies /api → localhost:8000)
npm start

# IMPORTANT: Tailwind CSS is NOT processed by Angular's PostCSS pipeline.
# Must be built separately via the Tailwind CLI:
npm run tailwind:build   # one-time build
npm run tailwind:watch   # watch mode (run alongside npm start)
```

**Critical:** After changing any Tailwind classes in `.ts` templates, run `tailwind:build`. The file `src/tailwind-out.css` is the generated output — it is committed and referenced in `angular.json`.

Angular dev server is at `http://localhost:4200`. The backend must be running at `http://localhost:8000` (Laravel Herd serves this).

---

## Architecture

### Backend Pipeline (per user message)

```
POST /api/chats/{chat}/messages
  └─ LegalChatController::sendMessage()
       └─ LegalChatOrchestratorService::handle()
            1. IntentClassifierService::classify()       ← rule-based, no API call
               • 'chat'   → skip retrieval, answer directly
               • 'search' → continue pipeline
            2. QueryExtractorService::extract()          ← OpenAI call, strips task words
            3. OpenAIEmbeddingService::embed()           ← text-embedding-3-large, 3072 dims
            4. LegalCaseRetrieverService::retrieve()     ← hybrid search (see below)
            5. OpenAILegalAnswerService::answer()        ← GPT-4.1 grounded answer
            6. Save assistant ChatMessage with meta (JSONB)
```

### Retrieval Pipeline (`LegalCaseRetrieverService`)

1. **Vector search** — cosine similarity via pgvector `<=>`, tiered thresholds: 0.65 → 0.50 → 0.40 (stops at first non-empty result)
2. **Metadata search** — `DISTINCT ON (case_id)` ILIKE across `case_num`, `court`, `chamber`, `category`, `dispute_subject`, `claim_type`, `kind`, `result`, **`content`** (judge/party name searches), limit 30
3. **Merge** — metadata-only cases get dummy similarity score 0.60
4. **Dynamic case limit** — if metadata finds > 3 unique cases, expand limit to `min(30, metaUniqueCases)`
5. **Scoring** — per case: `0.7 × max_sim + 0.3 × avg_sim`
6. **Reconstruction** — full decision text from ordered chunks; `excerpt` = matched chunks only

### Database

- **Connection name:** `pgvector` (custom, uses `PGV_*` env vars, NOT the default `DB_*` vars)
- **Key table:** `cases` — chunks of court decisions with 3072-dim embeddings
  - One decision = multiple rows (chunks), linked by `case_id`
  - Ordered by `meta->>'chunk_index'` or `id ASC`
- **Indexes:** ivfflat for vectors, GIN trgm for ILIKE on `content` and `case_num`
- **pg_trgm extension** is required (added in migration `000020`)

### OpenAI Context

- **Compact mode** (>3 decisions): 1200 chars/decision
- **Normal mode** (≤3 decisions): 7000 chars/decision (`MAX_CHARS_PER_DECISION`)
- Context limit: last 6 messages, old messages capped at 3000 chars each
- Citations URL pattern: `https://www.supremecourt.ge/ka/fullcase/{case_id}/0`

### Frontend Structure

```
src/app/
  core/
    models/         chat.model.ts, message.model.ts
    services/       api.service.ts, chat.service.ts   ← all signals-based state
  features/chat/
    pages/chat-page/
    components/
      sidebar/          ← chat list, new/delete chat
      chat-thread/      ← message list, scroll control
      message-item/     ← user bubble / assistant text + citations badge
      citation-list/    ← accordion cards per case
      chat-input/       ← autosize textarea, Enter=send
```

- All components use **standalone + inline templates** (no separate `.html` files)
- State management via Angular **signals** (`signal()`, `computed()`, `effect()`)
- Auto-scroll only on new messages (using `effect()` on `messages().length`), not on accordion open
- `ChatService` handles optimistic user message insertion before API responds

### Key Config Variables (`.env`)

| Variable | Purpose |
|---|---|
| `PGV_HOST/PORT/DATABASE/USERNAME/PASSWORD` | PostgreSQL with pgvector |
| `PGV_SEARCH_PATH` | Schema (default: public) |
| `OPENAI_API_KEY` | OpenAI key |
| `OPENAI_EMBEDDING_MODEL` | `text-embedding-3-large` |
| `OPENAI_CHAT_MODEL` | `gpt-4.1` |
| `MAX_CHARS_PER_DECISION` | Token budget per decision (default 7000) |
| `RETRIEVAL_CHUNK_LIMIT` | Vector search chunk limit (default 20) |
| `RETRIEVAL_CASE_LIMIT` | Default cases returned (default 3) |
| `RETRIEVAL_MIN_SCORE` | Starting similarity threshold (default 0.65) |
| `CONTEXT_HISTORY_MESSAGES` | Chat history window (default 6) |

### CORS

Configured in `config/cors.php` to allow `http://localhost:4200`. The `HandleCors` middleware is applied to all `api` routes in `bootstrap/app.php`.
