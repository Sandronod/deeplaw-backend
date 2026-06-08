<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use App\Services\Legal\LegalCaseRetrieverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnoses why retrieval eval shows misses.
 * Tests 5 gold cases and prints exactly what the retriever returns.
 *
 * Usage: php artisan eval:diagnose
 */
class EvalDiagnoseCommand extends Command
{
    protected $signature = 'eval:diagnose {--n=5 : Number of cases to test}';
    protected $description = 'Diagnose retrieval eval misses — trace what retriever returns';

    public function __construct(
        private readonly OllamaEmbeddingService    $embedder,
        private readonly LegalCaseRetrieverService $retriever,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $goldPath = storage_path('app/eval/gold_set.json');
        if (!file_exists($goldPath)) {
            $this->error('Gold set not found. Run eval:generate-gold first.');
            return self::FAILURE;
        }

        $goldSet = array_slice(json_decode(file_get_contents($goldPath), true), 0, (int) $this->option('n'));

        foreach ($goldSet as $item) {
            $expectedId = (int) $item['expected_case_id'];
            $query      = $item['query'];

            $this->line('');
            $this->line('══════════════════════════════════════════════');
            $this->line("Test #{$item['id']} | expected case_id: {$expectedId}");
            $this->line('Query: ' . mb_substr($query, 0, 120));

            // 1. Check Ollama embedding
            try {
                $embedding = $this->embedder->embed($query);
                $dims      = count($embedding);
                $this->info("  ✓ Embedding: {$dims} dims, first=[{$embedding[0]}, {$embedding[1]}, ...]");
            } catch (\Throwable $e) {
                $this->error("  ✗ Embedding FAILED: " . $e->getMessage());
                continue;
            }

            if (empty($embedding)) {
                $this->error('  ✗ Embedding returned empty array');
                continue;
            }

            // 2. Check case_embedding directly in DB for the expected case
            $caseRow = DB::connection('pgvector')
                ->table('court_cases')
                ->where('id', $expectedId)
                ->select(['id', 'case_num', 'case_card', 'case_embedding'])
                ->first();

            if (!$caseRow) {
                $this->error("  ✗ Expected case {$expectedId} not found in DB!");
                continue;
            }

            $hasEmbedding = !empty($caseRow->case_embedding);
            $this->line("  DB case: {$caseRow->case_num} | has case_embedding: " . ($hasEmbedding ? 'YES' : 'NO'));

            // 3. Direct case_embedding similarity (raw SQL)
            if ($hasEmbedding) {
                $vec = '[' . implode(',', $embedding) . ']';
                $simRow = DB::connection('pgvector')
                    ->select("SELECT 1 - (case_embedding <=> ?::vector) AS sim FROM court_cases WHERE id = ?", [$vec, $expectedId]);

                $sim = $simRow[0]->sim ?? null;
                $this->line('  case_embedding similarity to query: ' . ($sim !== null ? number_format((float)$sim, 4) : 'ERROR'));
            }

            // 4. Run full retriever
            $result      = $this->retriever->retrieve(rawEmbedding: $embedding, searchTerms: '', originalQuery: $query);
            $returnedIds = $result->matchedCaseIds;
            $count       = count($returnedIds);

            $this->line("  Retriever returned {$count} cases: [" . implode(', ', array_slice($returnedIds, 0, 10)) . ']');

            $rank = 0;
            foreach ($returnedIds as $pos => $caseId) {
                if ((int) $caseId === $expectedId) {
                    $rank = $pos + 1;
                    break;
                }
            }

            if ($rank > 0) {
                $this->info("  ✓ Found at rank #{$rank}");
            } else {
                $this->error("  ✗ NOT FOUND in returned set");

                // Check if it would've been found with lower threshold via direct similarity
                $vec     = '[' . implode(',', $embedding) . ']';
                $topRows = DB::connection('pgvector')
                    ->select("SELECT cc.case_id, 1 - (cc.embedding <=> ?::vector) AS sim FROM court_chunks cc WHERE cc.case_id = ? ORDER BY sim DESC LIMIT 1", [$vec, $expectedId]);

                if (!empty($topRows)) {
                    $chunkSim = $topRows[0]->sim;
                    $this->line("  Best chunk similarity for expected case: " . number_format((float)$chunkSim, 4));
                } else {
                    $this->line('  No chunks found for expected case_id!');
                }

                // Rank of expected case in case_embedding space (how many cases beat it?)
                if ($hasEmbedding && $sim !== null) {
                    $vec2 = '[' . implode(',', $embedding) . ']';
                    $above = DB::connection('pgvector')
                        ->select("SELECT COUNT(*) AS cnt FROM court_cases WHERE case_embedding IS NOT NULL AND 1 - (case_embedding <=> ?::vector) > ?", [$vec2, (float)$sim]);
                    $caseRank = (int)($above[0]->cnt ?? 0) + 1;
                    $this->line("  case_embedding rank among ALL cases: #{$caseRank}");
                }
            }
        }

        $this->newLine();
        return self::SUCCESS;
    }
}
