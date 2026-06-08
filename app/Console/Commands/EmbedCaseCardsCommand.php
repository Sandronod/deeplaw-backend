<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates bge-m3 embeddings from case_card (legal_issue + applied_articles)
 * and stores them in court_cases.case_embedding.
 *
 * Usage:
 *   php artisan cases:embed-cards                # all pending (case_card set, case_embedding null)
 *   php artisan cases:embed-cards --fresh        # re-embed all
 *   php artisan cases:embed-cards --from=5000    # resume from id
 */
class EmbedCaseCardsCommand extends Command
{
    protected $signature = 'cases:embed-cards
                            {--fresh  : Re-embed even if case_embedding already exists}
                            {--from=0 : Start from this court_cases.id}';

    protected $description = 'Generate bge-m3 embeddings for case cards (legal_issue + applied_articles)';

    public function __construct(
        private readonly OllamaEmbeddingService $embedder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        ini_set('max_execution_time', 0);

        $fresh  = $this->option('fresh');
        $fromId = (int) $this->option('from');

        $query = DB::connection('pgvector')
            ->table('court_cases')
            ->where('id', '>=', $fromId)
            ->whereNotNull('case_card')
            ->when(!$fresh, fn($q) => $q->whereNull('case_embedding'))
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to embed — all case cards already have embeddings.');
            return self::SUCCESS;
        }

        $this->info("Embedding {$total} case cards with bge-m3...");

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | errors:%message%');
        $bar->setMessage('0');
        $bar->start();

        $processed = 0;
        $errors    = 0;

        $query->chunk(50, function ($cases) use (&$processed, &$errors, $bar) {
            foreach ($cases as $case) {
                $card = is_string($case->case_card)
                    ? json_decode($case->case_card, true)
                    : (array) $case->case_card;

                $text = $this->buildEmbeddingText($card);

                try {
                    $embedding = $this->embedder->embed($text);

                    if (empty($embedding)) {
                        throw new \RuntimeException('Empty embedding returned');
                    }

                    $vec = '[' . implode(',', $embedding) . ']';

                    DB::connection('pgvector')
                        ->table('court_cases')
                        ->where('id', $case->id)
                        ->update(['case_embedding' => $vec]);

                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('EmbedCaseCards: failed', [
                        'case_id' => $case->id,
                        'error'   => $e->getMessage(),
                    ]);
                }

                $processed++;
                $bar->setMessage((string) $errors);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->table(
            ['Processed', 'Errors'],
            [[$processed, $errors]]
        );

        $this->info('✅ Embeddings generated. Retriever will now use case-level search.');

        return self::SUCCESS;
    }

    /**
     * Build embedding text from a case card.
     * Concatenates legal_issue + applied_articles for semantic search.
     */
    private function buildEmbeddingText(array $card): string
    {
        $parts = [];

        if (!empty($card['legal_issue'])) {
            $parts[] = $card['legal_issue'];
        }

        if (!empty($card['applied_articles']) && is_array($card['applied_articles'])) {
            $parts[] = 'გამოყენებული ნორმები: ' . implode(', ', $card['applied_articles']);
        }

        if (!empty($card['holding'])) {
            $parts[] = $card['holding'];
        }

        return implode('. ', $parts);
    }
}
