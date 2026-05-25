<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pdo = DB::connection('pgvector');

        // ── Queue table ───────────────────────────────────────────────────────
        $pdo->statement('
            CREATE TABLE IF NOT EXISTS const_court_queue (
                id            BIGSERIAL PRIMARY KEY,
                legal_id      INTEGER UNIQUE NOT NULL,
                title         TEXT,
                decision_type VARCHAR(100),
                status        VARCHAR(50) NOT NULL DEFAULT \'pending\',
                error         TEXT,
                queued_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                processed_at  TIMESTAMPTZ
            )
        ');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccq_status   ON const_court_queue(status)');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccq_legal_id ON const_court_queue(legal_id)');

        // ── Cases table ───────────────────────────────────────────────────────
        $pdo->statement('
            CREATE TABLE IF NOT EXISTS const_court_cases (
                id               BIGSERIAL PRIMARY KEY,
                legal_id         INTEGER UNIQUE NOT NULL,
                case_number      VARCHAR(100),
                case_name        TEXT,
                decision_type    VARCHAR(100),
                decision_date    DATE,
                publication_date TIMESTAMPTZ,
                college          VARCHAR(200),
                judges           TEXT,
                respondent       TEXT,
                result           TEXT,
                content          TEXT,
                content_hash     VARCHAR(64),
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccc_legal_id    ON const_court_cases(legal_id)');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccc_case_number ON const_court_cases(case_number)');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccc_date        ON const_court_cases(decision_date)');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccc_type        ON const_court_cases(decision_type)');
        $pdo->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccc_case_name_trgm ON const_court_cases USING gin(case_name gin_trgm_ops)');

        // ── Chunks table ──────────────────────────────────────────────────────
        $pdo->statement('
            CREATE TABLE IF NOT EXISTS const_court_chunks (
                id            BIGSERIAL PRIMARY KEY,
                case_id       BIGINT REFERENCES const_court_cases(id) ON DELETE CASCADE,
                legal_id      INTEGER NOT NULL,
                case_number   VARCHAR(100),
                decision_type VARCHAR(100),
                decision_date DATE,
                chunk_index   INTEGER NOT NULL,
                content       TEXT NOT NULL,
                embedding     vector(3072),
                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccch_case_id    ON const_court_chunks(case_id)');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccch_legal_id   ON const_court_chunks(legal_id)');
        $pdo->statement('CREATE INDEX IF NOT EXISTS idx_ccch_embedding  ON const_court_chunks USING hnsw ((embedding::halfvec(3072)) halfvec_cosine_ops) WITH (m=16, ef_construction=64)');
    }

    public function down(): void
    {
        $pdo = DB::connection('pgvector');
        $pdo->statement('DROP TABLE IF EXISTS const_court_chunks');
        $pdo->statement('DROP TABLE IF EXISTS const_court_cases');
        $pdo->statement('DROP TABLE IF EXISTS const_court_queue');
    }
};
