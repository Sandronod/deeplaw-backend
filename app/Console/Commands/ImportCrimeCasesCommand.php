<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCrimeCasesCommand extends Command
{
    protected $signature = 'cases:import-crime
                            {--batch=20 : Cases per batch}
                            {--sleep=100 : Milliseconds between batches}
                            {--from=0 : Start from this CaseID}';

    protected $description = 'Import criminal chamber decisions from SQL Server → court_cases + court_chunks';

    private const CHUNK_SIZE    = 7000;
    private const CHUNK_OVERLAP = 200;

    public function handle(OllamaEmbeddingService $embedder): int
    {
        $batchSize = (int) $this->option('batch');
        $sleep     = (int) $this->option('sleep');
        $fromId    = (int) $this->option('from');

        $total = DB::connection('sqlsrv')
            ->table('CaseCrime as cc')
            ->join('DecisionCrime as dc', 'dc.CaseID', '=', 'cc.CaseID')
            ->where('cc.CaseID', '>', $fromId)
            ->whereNotNull('dc.DecFullText')
            ->whereRaw('LEN(dc.DecFullText) > 50')
            ->distinct()
            ->count('cc.CaseID');

        $done = DB::connection('pgvector')
            ->table('court_cases')
            ->where('case_type', 'criminal')
            ->count();

        $this->info("სულ: {$total} cases | უკვე შემოტანილი: {$done}");
        $this->info("Batch: {$batchSize} | Model: bge-m3");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $lastId    = $fromId;
        $processed = 0;
        $skipped   = 0;

        while (true) {
            // Fetch batch from SQL Server with dictionary JOINs
            $rows = DB::connection('sqlsrv')
                ->table('CaseCrime as cc')
                ->join('DecisionCrime as dc', 'dc.CaseID', '=', 'cc.CaseID')
                ->leftJoin('dic_Chamber as ch', 'ch.dic_ChamberID', '=', 'cc.dic_ChamberID')
                ->leftJoin('dic_Result as r', 'r.dic_ResultID', '=', 'cc.dic_ResultID')
                ->leftJoin('dic_ClaimType as ct', 'ct.dic_ClaimTypeID', '=', 'cc.dic_ClaimID')
                ->select([
                    'cc.CaseID',
                    'cc.CaseNum',
                    'cc.CaseDate',
                    'cc.Defendant',
                    'cc.Kasatori',
                    'cc.Mopasuxe',
                    'cc.DanKval',
                    DB::raw("ch.Description as chamber"),
                    DB::raw("r.Description  as result"),
                    DB::raw("ct.Description as claim_type"),
                    'dc.DecFullText',
                ])
                ->where('cc.CaseID', '>', $lastId)
                ->whereNotNull('dc.DecFullText')
                ->whereRaw('LEN(dc.DecFullText) > 50')
                ->orderBy('cc.CaseID')
                ->limit($batchSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                // dispute_subject — defendant / kasatori / respondent
                $disputeParts   = array_filter([
                    $row->Defendant,
                    $row->Kasatori,
                    $row->Mopasuxe,
                ]);
                $disputeSubject = implode(' / ', $disputeParts) ?: null;

                // ── Insert court_cases ────────────────────────────────────────
                try {
                    DB::connection('pgvector')->statement("
                        INSERT INTO court_cases
                            (id, source_id, case_num, dispute_subject, case_date,
                             category, result, claim_type, kind,
                             chamber, court, case_type)
                        VALUES
                            (nextval('court_cases_id_seq'), ?, ?, ?, ?,
                             ?, ?, ?, NULL,
                             ?, NULL, 'criminal')
                        ON CONFLICT (source_id, case_type) DO NOTHING
                    ", [
                        $row->CaseID,
                        $row->CaseNum,
                        $disputeSubject,
                        $row->CaseDate,
                        $row->DanKval,          // category = crime qualification
                        $row->result,
                        $row->claim_type,
                        $row->chamber,
                    ]);
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("Skip case CaseID={$row->CaseID} (court_cases): " . $e->getMessage());
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Resolve the auto-generated id for this case
                $caseId = DB::connection('pgvector')
                    ->table('court_cases')
                    ->where('source_id', $row->CaseID)
                    ->where('case_type', 'criminal')
                    ->value('id');

                if (!$caseId) {
                    $bar->advance();
                    continue;
                }

                // ── Chunk + embed + insert court_chunks ───────────────────────
                $chunks = $this->chunkText($row->DecFullText);

                foreach ($chunks as $index => $chunkText) {
                    $text = mb_substr(
                        trim(preg_replace('/\s+/', ' ', $chunkText)),
                        0,
                        8000
                    );
                    if (mb_strlen($text) < 5) {
                        continue;
                    }

                    try {
                        $embedding = $embedder->embed($text);
                    } catch (\Throwable $e) {
                        $this->newLine();
                        $this->warn("Skip chunk case={$row->CaseID} chunk={$index}: " . $e->getMessage());
                        continue;
                    }

                    $vec = '[' . implode(',', $embedding) . ']';

                    DB::connection('pgvector')->statement('
                        INSERT INTO court_chunks (case_id, chunk_index, content, embedding)
                        VALUES (?, ?, ?, ?::vector)
                    ', [$caseId, $index, $chunkText, $vec]);
                }

                $processed++;
                $bar->advance();
            }

            $lastId = $rows->last()->CaseID;
            $this->line(" [{$processed}/{$total}] last_case_id={$lastId} skipped={$skipped}");

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("დასრულდა! შემოტანილი: {$processed} cases | გამოტოვებული: {$skipped}");

        return self::SUCCESS;
    }

    private function chunkText(string $text): array
    {
        $text = trim(preg_replace('/\r\n|\r/', "\n", $text));
        $len  = mb_strlen($text);

        if ($len <= self::CHUNK_SIZE) {
            return [$text];
        }

        $chunks = [];
        $start  = 0;
        $step   = self::CHUNK_SIZE - self::CHUNK_OVERLAP;

        while ($start < $len) {
            $chunks[] = mb_substr($text, $start, self::CHUNK_SIZE);
            $start   += $step;
        }

        return $chunks;
    }
}
