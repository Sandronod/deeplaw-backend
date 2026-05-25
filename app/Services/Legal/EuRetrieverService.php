<?php

namespace App\Services\Legal;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EuRetrieverService
{
    private const THRESHOLDS  = [0.60, 0.45, 0.35];
    private const CHUNK_LIMIT = 30;

    public function __construct(private readonly OllamaEmbeddingService $embedder) {}

    /**
     * @param  string $query   Raw user query (embedded internally with bge-m3)
     * @param  int    $limit   Max documents returned
     * @param  string $source  'all' | 'legislation' | 'case_law'
     */
    public function retrieve(
        string $query,
        int    $limit     = 5,
        string $source    = 'all',
        array  $embedding = [],
    ): array {
        if (empty($embedding)) {
            $embedding = $this->embedder->embed($query);
        }
        $sourceFilter = $source !== 'all'
            ? "AND source = " . DB::connection('pgvector')->getPdo()->quote($source)
            : '';

        // ── 1. Vector search ──────────────────────────────────────────────────
        $vectorRows = collect();
        foreach (self::THRESHOLDS as $threshold) {
            $vectorRows = $this->vectorSearch($embedding, self::CHUNK_LIMIT, $threshold, $sourceFilter);
            if ($vectorRows->isNotEmpty()) {
                Log::debug('EuRetriever: vector hit', ['threshold' => $threshold, 'count' => $vectorRows->count()]);
                break;
            }
        }

        // ── 2. Keyword fallback ───────────────────────────────────────────────
        $keywordRows = collect();
        $terms = array_filter(explode(' ', preg_replace('/\s+/', ' ', trim($query))));
        if (!empty($terms)) {
            $keywordRows = $this->keywordSearch($terms, 20, $sourceFilter);
            Log::debug('EuRetriever: keyword', ['count' => $keywordRows->count()]);
        }

        // ── 3. Merge by cellar_id ─────────────────────────────────────────────
        $merged = [];

        foreach ($vectorRows as $row) {
            $id = $row->cellar_id;
            $merged[$id] = [
                'similarity' => (float) $row->similarity,
                'row'        => $row,
            ];
        }

        foreach ($keywordRows as $row) {
            $id = $row->cellar_id;
            if (!isset($merged[$id])) {
                $merged[$id] = ['similarity' => 0.55, 'row' => $row];
            }
        }

        usort($merged, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $merged = array_slice($merged, 0, $limit);

        // ── 4. Reconstruct full content per document ──────────────────────────
        return array_map(fn ($m) => $this->buildResult($m['row'], $m['similarity']), $merged);
    }

    private function vectorSearch(
        array  $embedding,
        int    $limit,
        float  $threshold,
        string $sourceFilter,
    ): \Illuminate\Support\Collection {
        $vector = '[' . implode(',', $embedding) . ']';

        $sql = <<<SQL
            SELECT DISTINCT ON (cellar_id)
                id, cellar_id, doc_type, source, court, case_num, title,
                doc_date, content, meta,
                1 - (embedding <=> '{$vector}'::vector) AS similarity
            FROM eu_documents
            WHERE embedding IS NOT NULL
              AND 1 - (embedding <=> '{$vector}'::vector) >= {$threshold}
              {$sourceFilter}
            ORDER BY cellar_id, similarity DESC
            LIMIT {$limit}
        SQL;

        return DB::connection('pgvector')->table(DB::raw("({$sql}) AS t"))
            ->orderByDesc('similarity')
            ->limit($limit)
            ->get();
    }

    private function keywordSearch(
        array  $terms,
        int    $limit,
        string $sourceFilter,
    ): \Illuminate\Support\Collection {
        $conditions = [];
        foreach ($terms as $term) {
            $quoted       = DB::connection('pgvector')->getPdo()->quote('%' . $term . '%');
            $conditions[] = "(content ILIKE {$quoted} OR title ILIKE {$quoted})";
        }
        $where = implode(' AND ', $conditions);

        $sql = <<<SQL
            SELECT DISTINCT ON (cellar_id)
                id, cellar_id, doc_type, source, court, case_num, title,
                doc_date, content, meta
            FROM eu_documents
            WHERE ({$where})
              {$sourceFilter}
            ORDER BY cellar_id, doc_date DESC NULLS LAST
            LIMIT {$limit}
        SQL;

        return collect(DB::connection('pgvector')->select($sql));
    }

    private function buildResult(object $row, float $similarity): array
    {
        // Fetch all chunks for this document to reconstruct full content
        $allChunks = DB::connection('pgvector')
            ->table('eu_documents')
            ->where('cellar_id', $row->cellar_id)
            ->orderByRaw("(meta->>'chunk_index')::int ASC")
            ->pluck('content');

        $fullContent = $allChunks->implode("\n\n");

        $meta = is_string($row->meta) ? json_decode($row->meta, true) : (array) $row->meta;

        return [
            'cellar_id'  => $row->cellar_id,
            'doc_type'   => $row->doc_type,
            'source'     => $row->source,
            'court'      => $row->court,
            'case_num'   => $row->case_num,
            'title'      => $row->title,
            'doc_date'   => $row->doc_date,
            'similarity' => round($similarity, 4),
            'content'    => $fullContent,
            'excerpt'    => mb_substr($row->content, 0, 600),
            'url'        => $meta['url'] ?? null,
        ];
    }
}
