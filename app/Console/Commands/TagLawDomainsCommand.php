<?php

namespace App\Console\Commands;

use App\Services\Legal\LegalDomainClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Batch-tags matsne_documents with legal domain.
 *
 * hierarchy_level is already filled by the migration (from doc_type).
 * This command fills the domain column:
 *   1. Rule-based classifier (title keywords) — free, instant
 *   2. GPT-4.1-mini fallback for titles the rule-based can't classify
 *
 * Usage:
 *   php artisan laws:tag-domains              # tag untagged only
 *   php artisan laws:tag-domains --retag      # retag everything
 *   php artisan laws:tag-domains --batch=100  # GPT chunk size
 *   php artisan laws:tag-domains --dry-run    # preview, no writes
 */
class TagLawDomainsCommand extends Command
{
    protected $signature = 'laws:tag-domains
                            {--retag    : Retag already-tagged documents too}
                            {--batch=50 : Chunk size for GPT fallback}
                            {--dry-run  : Preview without writing}';

    protected $description = 'Tag matsne_documents with legal domain (civil, criminal, admin…)';

    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(private readonly LegalDomainClassifier $classifier)
    {
        parent::__construct();
        $this->apiKey  = config('openai.api_key');
        $this->model   = config('openai.extraction_model', 'gpt-4.1-mini');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    public function handle(): int
    {
        $retag  = $this->option('retag');
        $batch  = (int) $this->option('batch');
        $dryRun = $this->option('dry-run');

        $query = DB::connection('pgvector')
            ->table('matsne_documents')
            ->select(['id', 'title', 'doc_type']);

        if (!$retag) {
            $query->whereNull('domain');
        }

        $total = $query->count();
        $this->info("matsne_documents to tag: {$total}" . ($dryRun ? ' (dry-run)' : ''));

        if ($total === 0) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }

        $bar         = $this->output->createProgressBar($total);
        $ruleTagged  = 0;
        $gptTagged   = 0;
        $gptFallback = [];

        $query->orderBy('id')->chunk(500, function ($docs) use (
            $bar, $dryRun, $batch, &$ruleTagged, &$gptTagged, &$gptFallback
        ) {
            foreach ($docs as $doc) {
                $title   = (string) ($doc->title    ?? '');
                $docType = (string) ($doc->doc_type ?? '');

                $domain = $this->classifier->classifyText($title);

                if ($domain) {
                    if (!$dryRun) {
                        DB::connection('pgvector')
                            ->table('matsne_documents')
                            ->where('id', $doc->id)
                            ->update(['domain' => $domain]);
                    }
                    $ruleTagged++;
                } else {
                    $gptFallback[] = ['id' => $doc->id, 'title' => $title, 'doc_type' => $docType];

                    if (count($gptFallback) >= $batch) {
                        $gptTagged  += $this->tagWithGpt($gptFallback, $dryRun);
                        $gptFallback = [];
                    }
                }

                $bar->advance();
            }
        });

        if (!empty($gptFallback)) {
            $gptTagged += $this->tagWithGpt($gptFallback, $dryRun);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Rule-based: {$ruleTagged}, GPT: {$gptTagged}, unclassified: " . ($total - $ruleTagged - $gptTagged));

        return self::SUCCESS;
    }

    // ── GPT fallback ──────────────────────────────────────────────────────────

    private function tagWithGpt(array $docs, bool $dryRun): int
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => count($docs) * 15,
                    'messages'    => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user',   'content' => $this->buildPrompt($docs)],
                    ],
                ]);

            if (!$response->successful()) {
                return 0;
            }

            $mappings = $this->parseResponse(
                trim($response->json('choices.0.message.content') ?? '')
            );

            $tagged = 0;
            foreach ($docs as $doc) {
                $domain = $mappings[$doc['id']] ?? null;
                if (!$domain) continue;

                if (!$dryRun) {
                    DB::connection('pgvector')
                        ->table('matsne_documents')
                        ->where('id', $doc['id'])
                        ->update(['domain' => $domain]);
                }
                $tagged++;
            }

            return $tagged;

        } catch (\Throwable $e) {
            Log::warning('TagLawDomains: GPT exception — ' . $e->getMessage());
            return 0;
        }
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are a Georgian legal document domain classifier.
Given a list of Georgian law/regulation titles with IDs, output ONLY: id=domain pairs, one per line.
Valid domains: civil, criminal, admin, corporate, labor, property, tax, family, procedure, echr

Example:
42=admin
57=labor

No explanation. No other text. Just id=domain lines.
PROMPT;
    }

    private function buildPrompt(array $docs): string
    {
        $lines = "Classify each Georgian legal document by domain:\n\n";
        foreach ($docs as $d) {
            $lines .= "ID {$d['id']}: {$d['title']} [{$d['doc_type']}]\n";
        }
        return $lines;
    }

    private function parseResponse(string $content): array
    {
        $valid  = ['civil','criminal','admin','corporate','labor','property','tax','family','procedure','echr'];
        $result = [];
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^(\d+)\s*=\s*(\w+)$/', trim($line), $m)) {
                $domain = strtolower($m[2]);
                if (in_array($domain, $valid, true)) {
                    $result[(int) $m[1]] = $domain;
                }
            }
        }
        return $result;
    }
}
