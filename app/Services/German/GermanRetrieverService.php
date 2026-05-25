<?php

namespace App\Services\German;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GermanRetrieverService
{
    private const THRESHOLDS  = [0.55, 0.45, 0.35];
    private const CHUNK_LIMIT = 40;
    private const CASE_LIMIT  = 5;

    public function __construct(
        private readonly OllamaEmbeddingService $embedder,
    ) {}

    /**
     * @return array<int, array{case_id, external_id, court_name, level_of_appeal, jurisdiction, date_year, excerpt, similarity}>
     */
    public function retrieve(string $query, int $limit = self::CASE_LIMIT, array $embedding = []): array
    {
        if (empty($embedding)) {
            $embedding = $this->embedder->embed($query);
        }

        if (empty($embedding)) {
            Log::debug('GermanRetriever: embedding failed');
            return [];
        }

        $results = $this->vectorSearch($embedding, $limit);

        Log::debug('GermanRetriever: complete', [
            'query'   => mb_substr($query, 0, 80),
            'results' => count($results),
        ]);

        return $results;
    }

    private function vectorSearch(array $embedding, int $limit): array
    {
        $vec = '[' . implode(',', $embedding) . ']';

        foreach (self::THRESHOLDS as $threshold) {
            $rows = DB::connection('pgvector')->select("
                SELECT
                    gc.case_id,
                    gc.external_id,
                    gc.court_name,
                    gc.level_of_appeal,
                    gc.jurisdiction,
                    gc.date_year,
                    gc.chunk_index,
                    gc.content,
                    1 - (gc.embedding <=> :emb::vector) AS similarity
                FROM german_chunks_de gc
                WHERE gc.embedding IS NOT NULL
                  AND 1 - (gc.embedding <=> :emb2::vector) >= :threshold
                ORDER BY similarity DESC
                LIMIT :chunk_limit
            ", [
                'emb'         => $vec,
                'emb2'        => $vec,
                'threshold'   => $threshold,
                'chunk_limit' => self::CHUNK_LIMIT,
            ]);

            if (!empty($rows)) {
                return $this->groupByCases($rows, $limit);
            }
        }

        return [];
    }

    private function groupByCases(array $rows, int $limit): array
    {
        $byCase = [];

        foreach ($rows as $row) {
            $id = $row->case_id;
            if (!isset($byCase[$id])) {
                $byCase[$id] = [
                    'case_id'        => $id,
                    'external_id'    => $row->external_id,
                    'court_name'     => $row->court_name,
                    'level_of_appeal'=> $row->level_of_appeal,
                    'jurisdiction'   => $row->jurisdiction,
                    'date_year'      => $row->date_year,
                    'similarity'     => (float) $row->similarity,
                    'chunks'         => [],
                ];
            }

            if ((float) $row->similarity > $byCase[$id]['similarity']) {
                $byCase[$id]['similarity'] = (float) $row->similarity;
            }

            $byCase[$id]['chunks'][] = $row->content;
        }

        usort($byCase, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_map(function (array $c) {
            $c['excerpt'] = mb_substr(implode("\n\n", array_slice($c['chunks'], 0, 3)), 0, 1500);
            unset($c['chunks']);
            return $c;
        }, array_slice($byCase, 0, $limit));
    }
}
