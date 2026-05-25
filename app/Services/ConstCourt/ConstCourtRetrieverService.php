<?php

namespace App\Services\ConstCourt;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConstCourtRetrieverService
{
    private const CHUNK_LIMIT    = 20;
    private const CASE_LIMIT     = 3;
    private const SIM_THRESHOLDS = [0.65, 0.50, 0.40];
    private const MAX_CHARS      = 7000;
    private const BASE_URL       = 'https://constcourt.ge/ka/judicial-acts';

    public function __construct(
        private readonly OllamaEmbeddingService $embedder,
    ) {}

    /**
     * Retrieve relevant Constitutional Court decisions.
     *
     * @param  string     $query      User question (used only if $embedding is null)
     */
    public function retrieve(string $query, ?array $embedding = null): array
    {
        try {
            $embedding ??= $this->embedder->embed($query);
        } catch (\Throwable $e) {
            Log::warning('ConstCourtRetriever: embedding failed', ['error' => $e->getMessage()]);
            return [];
        }

        if (empty($embedding)) return [];

        $vectorStr = '[' . implode(',', $embedding) . ']';
        $chunks    = $this->vectorSearch($vectorStr);

        if (empty($chunks)) return [];

        return $this->buildResults($chunks);
    }

    // ── Vector search ─────────────────────────────────────────────────────────

    private function vectorSearch(string $vectorStr): array
    {
        foreach (self::SIM_THRESHOLDS as $threshold) {
            $rows = DB::connection('pgvector')->select(
                'SELECT ck.legal_id, ck.case_number, ck.decision_type, ck.decision_date,
                        ck.content AS chunk_content,
                        1 - (ck.embedding <=> ?::vector) AS similarity
                   FROM const_court_chunks ck
                  WHERE 1 - (ck.embedding <=> ?::vector) >= ?
                  ORDER BY ck.embedding <=> ?::vector
                  LIMIT ?',
                [$vectorStr, $vectorStr, $threshold, $vectorStr, self::CHUNK_LIMIT]
            );

            if (!empty($rows)) return $rows;
        }

        return [];
    }

    // ── Result building ───────────────────────────────────────────────────────

    private function buildResults(array $chunks): array
    {
        // Group by legal_id
        $grouped = [];
        foreach ($chunks as $row) {
            $key = $row->legal_id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'legal_id'      => $row->legal_id,
                    'case_number'   => $row->case_number,
                    'decision_type' => $row->decision_type,
                    'decision_date' => $row->decision_date,
                    'chunks'        => [],
                    'sims'          => [],
                ];
            }
            $grouped[$key]['chunks'][] = $row->chunk_content;
            $grouped[$key]['sims'][]   = (float) $row->similarity;
        }

        // Score: 70% max + 30% avg similarity
        uasort($grouped, function ($a, $b) {
            $sa = 0.7 * max($a['sims']) + 0.3 * (array_sum($a['sims']) / count($a['sims']));
            $sb = 0.7 * max($b['sims']) + 0.3 * (array_sum($b['sims']) / count($b['sims']));
            return $sb <=> $sa;
        });

        $results = [];

        foreach (array_slice($grouped, 0, self::CASE_LIMIT) as $group) {
            $full = DB::connection('pgvector')
                ->table('const_court_cases')
                ->where('legal_id', $group['legal_id'])
                ->first();

            if (!$full) continue;

            $score   = 0.7 * max($group['sims']) + 0.3 * (array_sum($group['sims']) / count($group['sims']));
            $excerpt = implode("\n\n", array_unique($group['chunks']));

            $results[] = [
                'legal_id'      => $full->legal_id,
                'case_number'   => $full->case_number,
                'case_name'     => $full->case_name,
                'decision_type' => $full->decision_type,
                'decision_date' => $full->decision_date,
                'college'       => $full->college,
                'respondent'    => $full->respondent,
                'result'        => $full->result,
                'excerpt'       => $excerpt,
                'full_text'     => mb_substr($full->content ?? '', 0, self::MAX_CHARS),
                'score'         => round($score, 4),
                'url'           => self::BASE_URL . '?legal=' . $full->legal_id,
            ];
        }

        Log::debug('ConstCourtRetriever: results', ['count' => count($results)]);

        return $results;
    }
}
