<?php

namespace App\Console\Commands;

use App\Models\CaseAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbedCases extends Command
{
    protected $signature = 'embed:cases
                            {--fresh : Truncate cases table before starting}
                            {--from=0 : Start from this CaseID}
                            {--batch=50 : Cases per MSSQL batch}';

    protected $description = 'Embed court decisions from MSSQL into pgvector cases table';

    private const CHUNK_SIZE    = 1500;
    private const CHUNK_OVERLAP = 200;
    private const EMBED_BATCH   = 10;   // chunks per OpenAI request

    public function handle(): int
    {
        ini_set('max_execution_time', 0);

        if ($this->option('fresh')) {
            DB::connection('pgvector')->statement('TRUNCATE TABLE cases RESTART IDENTITY');
            $this->info('Cases table truncated.');
        }

        $batchSize = (int) $this->option('batch');

        // Load already-embedded case_ids to skip
        $embedded = DB::connection('pgvector')
            ->table('cases')
            ->distinct()
            ->pluck('case_id')
            ->flip()
            ->all();

        // Auto-detect starting CaseID: use --from if given, else max embedded case_id
        $fromOption = $this->option('from');
        if ($fromOption !== '0') {
            $fromId = (int) $fromOption;
        } else {
            $fromId = empty($embedded) ? 0 : (int) DB::connection('pgvector')->table('cases')->max('case_id');
        }

        $this->info('Already embedded: ' . count($embedded) . ' cases. Starting from CaseID > ' . $fromId);

        $totalCases  = 0;
        $totalChunks = 0;
        $lastId      = $fromId;

        do {
            $batch = CaseAdmin::with(['DecisionAdmin', 'dic_Category', 'dic_Chamber', 'dic_Result', 'dic_ClaimType', 'dic_Kind'])
                ->where('CaseID', '>', $lastId)
                ->orderBy('CaseID')
                ->limit($batchSize)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $lastId = $batch->last()->CaseID;

            foreach ($batch as $case) {
                if (isset($embedded[$case->CaseID])) {
                    $this->line("  skip  CaseID={$case->CaseID} (already embedded)");
                    continue;
                }

                $text = $this->extractText($case);

                if (empty(trim($text))) {
                    $this->warn("  empty CaseID={$case->CaseID} — skipping");
                    continue;
                }

                $chunks = $this->chunkText($text);
                $total  = count($chunks);

                // Embed in sub-batches of EMBED_BATCH
                for ($i = 0; $i < $total; $i += self::EMBED_BATCH) {
                    $slice = array_slice($chunks, $i, self::EMBED_BATCH);
                    try {
                        $embeddings = $this->batchEmbed($slice);
                    } catch (\Throwable $e) {
                        $this->warn("  SKIP CaseID={$case->CaseID} chunk_batch={$i} — " . $e->getMessage());
                        Log::warning('embed:cases skip', ['case_id' => $case->CaseID, 'error' => $e->getMessage()]);
                        continue 2; // skip entire case, move to next
                    }

                    foreach ($slice as $j => $chunk) {
                        $chunkIndex = $i + $j;
                        DB::connection('pgvector')->insert(
                            "INSERT INTO cases (
                                case_id, case_num, dispute_subject, case_date,
                                category, result, claim_type, kind,
                                chamber, court, content, embedding, meta
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::vector, ?::jsonb)",
                            [
                                $case->CaseID,
                                $case->CaseNum       ?? '',
                                $case->DisputeSubject ?? '',
                                $case->CaseDate       ? date('Y-m-d', strtotime($case->CaseDate)) : null,
                                $case->dic_Category?->Description  ?? '',
                                $case->dic_Result?->Description    ?? '',
                                $case->dic_ClaimType?->Description ?? '',
                                $case->dic_Kind?->Description      ?? '',
                                $case->dic_Chamber?->Description   ?? '',
                                'საქართველოს უზენაესი სასამართლო',
                                $chunk,
                                '[' . implode(',', $embeddings[$j]) . ']',
                                json_encode([
                                    'chunk_index'  => $chunkIndex,
                                    'total_chunks' => $total,
                                ], JSON_UNESCAPED_UNICODE),
                            ]
                        );
                    }
                }

                $totalChunks += $total;
                $totalCases++;

                $this->line("  done  CaseID={$case->CaseID}  chunks={$total}  total_cases={$totalCases}  total_chunks={$totalChunks}");
                Log::info("embed:cases", ['case_id' => $case->CaseID, 'chunks' => $total]);
            }

        } while ($batch->count() === $batchSize);

        $this->info("Finished. Cases embedded: {$totalCases}, total chunks: {$totalChunks}");
        $this->info("Run the following SQL to rebuild the ivfflat index with optimal lists:");
        $lists = max(100, (int) round(sqrt($totalChunks)));
        $this->line("  DROP INDEX idx_cases_embedding;");
        $this->line("  CREATE INDEX idx_cases_embedding ON cases USING ivfflat (embedding halfvec_cosine_ops) WITH (lists={$lists});");

        return self::SUCCESS;
    }

    /**
     * Extract clean text from FileHtml (preserving prima ^N) or fallback to DecFullText.
     */
    private function extractText(CaseAdmin $case): string
    {
        $html = $case->DecisionAdmin?->FileHtml ?? '';

        if (!empty(trim(strip_tags($html)))) {
            // Convert <sup>N</sup> → ^N before stripping
            $text = preg_replace('/<sup[^>]*>(\d+)<\/sup>/i', '^$1', $html);
            // Block elements → newline
            $text = preg_replace('/<\/?(p|div|br|li|tr|h[1-6])[^>]*>/i', "\n", $text);
            // Strip remaining tags
            $text = strip_tags($text);
            // Decode HTML entities
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            // Fallback to plain text
            $text = $case->DecisionAdmin?->DecFullText ?? '';
        }

        // Fix fragmented Georgian characters: "გ ა ნ ჩ ი ნ ე ბ ა" → "განჩინება"
        // Minimum 3 consecutive single Georgian chars separated by spaces (safe threshold).
        $text = preg_replace_callback('/(?:[\x{10D0}-\x{10FF}] ){2,}[\x{10D0}-\x{10FF}]/u', function ($m) {
            return str_replace(' ', '', $m[0]);
        }, $text);

        // Remove null bytes and other control chars that break JSON serialization
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Ensure valid UTF-8 (replaces lone surrogates / invalid sequences)
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Split text into overlapping chunks.
     */
    private function chunkText(string $text): array
    {
        $chunks = [];
        $len    = mb_strlen($text, 'UTF-8');
        $step   = self::CHUNK_SIZE - self::CHUNK_OVERLAP;
        $start  = 0;

        while ($start < $len) {
            $chunk = mb_substr($text, $start, self::CHUNK_SIZE, 'UTF-8');
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            $start += $step;
        }

        return $chunks;
    }

    /**
     * Batch embed multiple texts in a single OpenAI request.
     *
     * @return array<int, float[]>
     */
    private function batchEmbed(array $texts): array
    {
        $response = Http::withToken(config('openai.api_key'))
            ->timeout(120)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => config('openai.embedding_model', 'text-embedding-3-large'),
                'input' => $texts,
            ])
            ->throw()
            ->json();

        // Sort by index to preserve order
        $data = $response['data'];
        usort($data, fn($a, $b) => $a['index'] <=> $b['index']);

        return array_map(fn($d) => $d['embedding'], $data);
    }
}
