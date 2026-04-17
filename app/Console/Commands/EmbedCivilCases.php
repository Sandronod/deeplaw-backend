<?php

namespace App\Console\Commands;

use App\Models\CaseCivil;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbedCivilCases extends Command
{
    protected $signature = 'embed:civil-cases
                            {--fresh : Delete civil rows from cases table before starting}
                            {--from=0 : Start from this CaseID}
                            {--ids= : Comma-separated list of specific CaseIDs to embed}
                            {--limit=0 : Stop after this many cases (0 = no limit, use 1 for testing)}
                            {--batch=50 : Cases per MSSQL batch}';

    protected $description = 'Embed civil court decisions from MSSQL into pgvector cases table';

    private const CHUNK_SIZE    = 1500;
    private const CHUNK_OVERLAP = 200;
    private const EMBED_BATCH   = 10;

    public function handle(): int
    {
        ini_set('max_execution_time', 0);

        if ($this->option('fresh')) {
            $deleted = DB::connection('pgvector')
                ->table('cases')
                ->where('case_type', 'civil')
                ->delete();
            $this->info("Deleted {$deleted} civil rows from cases table.");
        }

        $batchSize  = (int) $this->option('batch');
        $limit      = (int) $this->option('limit');

        // --ids mode: embed specific CaseIDs only
        $idsOption = $this->option('ids');
        if ($idsOption !== null) {
            $specificIds = array_filter(array_map('intval', explode(',', $idsOption)));
            $this->info('IDs mode: ' . count($specificIds) . ' case(s) to embed.');

            $totalCases  = 0;
            $totalChunks = 0;

            $cases = CaseCivil::with(['DecisionCivil', 'dic_Category', 'dic_Chamber', 'dic_Result', 'dic_ClaimType', 'dic_Kind'])
                ->whereIn('CaseID', $specificIds)
                ->orderBy('CaseID')
                ->get();

            foreach ($cases as $case) {
                $chunks = $this->processCase($case, $totalCases, $totalChunks);
                if ($chunks !== null) {
                    $totalChunks += $chunks;
                    $totalCases++;
                }
            }

            $this->info("Finished. Civil cases embedded: {$totalCases}, total chunks: {$totalChunks}");
            return self::SUCCESS;
        }

        // Load already-embedded civil case_ids to skip
        $embedded = DB::connection('pgvector')
            ->table('cases')
            ->where('case_type', 'civil')
            ->distinct()
            ->pluck('case_id')
            ->flip()
            ->all();

        $fromOption = $this->option('from');
        if ($fromOption !== '0') {
            $fromId = (int) $fromOption;
        } else {
            $fromId = empty($embedded) ? 0 : (int) DB::connection('pgvector')
                ->table('cases')
                ->where('case_type', 'civil')
                ->max('case_id');
        }

        $this->info('Already embedded: ' . count($embedded) . ' civil cases. Starting from CaseID > ' . $fromId);
        if ($limit > 0) {
            $this->info("Limit: {$limit} case(s) — test mode.");
        }

        $totalCases  = 0;
        $totalChunks = 0;
        $lastId      = $fromId;

        do {
            $batch = CaseCivil::with(['DecisionCivil', 'dic_Category', 'dic_Chamber', 'dic_Result', 'dic_ClaimType', 'dic_Kind'])
                ->where('CaseID', '>', $lastId)
                ->orderBy('CaseID')
                ->limit($batchSize)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $lastId = $batch->last()->CaseID;

            foreach ($batch as $case) {
                if ($limit > 0 && $totalCases >= $limit) {
                    break 2;
                }

                if (isset($embedded[$case->CaseID])) {
                    $this->line("  skip  CaseID={$case->CaseID} (already embedded)");
                    continue;
                }

                $chunkCount = $this->processCase($case, $totalCases, $totalChunks);
                if ($chunkCount !== null) {
                    $totalChunks += $chunkCount;
                    $totalCases++;
                }
            }

        } while ($batch->count() === $batchSize);

        $this->info("Finished. Civil cases embedded: {$totalCases}, total chunks: {$totalChunks}");

        if ($totalChunks > 0) {
            $lists = max(100, (int) round(sqrt($totalChunks)));
            $this->line('After full import, rebuild the ivfflat index:');
            $this->line("  DROP INDEX idx_cases_embedding;");
            $this->line("  CREATE INDEX idx_cases_embedding ON cases USING ivfflat (embedding halfvec_cosine_ops) WITH (lists={$lists});");
        }

        return self::SUCCESS;
    }

    private function processCase(CaseCivil $case, int $totalCases, int $totalChunks): ?int
    {
        $text = $this->extractText($case);

        if (empty(trim($text))) {
            $this->warn("  empty CaseID={$case->CaseID} — skipping");
            return null;
        }

        $chunks = $this->chunkText($text);
        $total  = count($chunks);

        for ($i = 0; $i < $total; $i += self::EMBED_BATCH) {
            $slice = array_slice($chunks, $i, self::EMBED_BATCH);
            try {
                $embeddings = $this->batchEmbed($slice);
            } catch (\Throwable $e) {
                $this->warn("  SKIP CaseID={$case->CaseID} chunk_batch={$i} — " . $e->getMessage());
                Log::warning('embed:civil-cases skip', ['case_id' => $case->CaseID, 'error' => $e->getMessage()]);
                return null;
            }

            foreach ($slice as $j => $chunk) {
                $chunkIndex = $i + $j;
                DB::connection('pgvector')->insert(
                    "INSERT INTO cases (
                        case_id, case_num, dispute_subject, case_date,
                        category, result, claim_type, kind,
                        chamber, court, content, embedding, meta, case_type
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::vector, ?::jsonb, ?)",
                    [
                        $case->CaseID,
                        $case->CaseNum        ?? '',
                        $case->DisputeSubject  ?? '',
                        $case->CaseDate        ? date('Y-m-d', strtotime($case->CaseDate)) : null,
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
                        'civil',
                    ]
                );
            }
        }

        $this->line("  done  CaseID={$case->CaseID}  chunks={$total}  total_cases=" . ($totalCases + 1) . "  total_chunks=" . ($totalChunks + $total));
        Log::info('embed:civil-cases', ['case_id' => $case->CaseID, 'chunks' => $total]);

        return $total;
    }

    private function extractText(CaseCivil $case): string
    {
        $html = $case->DecisionCivil?->FileHtml ?? '';

        if (!empty(trim(strip_tags($html)))) {
            $text = preg_replace('/<sup[^>]*>(\d+)<\/sup>/i', '^$1', $html);
            $text = preg_replace('/<\/?(p|div|br|li|tr|h[1-6])[^>]*>/i', "\n", $text);
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $text = $case->DecisionCivil?->DecFullText ?? '';
        }

        // Fix fragmented Georgian characters: "გ ა ნ ჩ ი ნ ე ბ ა" → "განჩინება"
        $text = preg_replace_callback('/(?:[\x{10D0}-\x{10FF}] ){2,}[\x{10D0}-\x{10FF}]/u', function ($m) {
            return str_replace(' ', '', $m[0]);
        }, $text);

        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

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

        $data = $response['data'];
        usort($data, fn($a, $b) => $a['index'] <=> $b['index']);

        return array_map(fn($d) => $d['embedding'], $data);
    }
}
