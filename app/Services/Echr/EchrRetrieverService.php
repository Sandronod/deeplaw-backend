<?php

namespace App\Services\Echr;

use App\DTOs\EchrResult;
use App\DTOs\ParsedQuery;
use App\Models\EchrCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Local-first ECHR case retriever.
 *
 * Priority order (mirrors LawRetrieverService pattern):
 *  1. Exact application number match
 *  2. Article code keyword filter
 *  3. Multi-term keyword search (title + summary)
 *  4. Vector search over echr_paragraphs (semantic fallback)
 *  5. If nothing found locally → trigger on-demand HUDOC fetch → retry once
 */
class EchrRetrieverService
{
    private const CACHE_TTL_SECONDS   = 3600; // 1 hour
    private const INDEX_VERSION_KEY   = 'echr_index_version';
    private const TOP_K               = 3;
    private const VECTOR_THRESHOLD    = 0.65;

    public function __construct(
        private readonly EchrRepositoryService $repo,
        private readonly EchrFetchLockService  $lock,
    ) {}

    /**
     * @param  float[]     $rawEmbedding   Pre-computed query embedding (3072-dim)
     * @param  string      $userQuestion   Original user question
     * @param  ParsedQuery $parsed         Parsed filters (echrArticle, echrTopic, etc.)
     * @return EchrResult[]
     */
    public function retrieve(array $rawEmbedding, string $userQuestion, ParsedQuery $parsed): array
    {
        if (empty($rawEmbedding)) {
            return [];
        }

        $cacheKey = $this->cacheKey($userQuestion, $parsed);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('EchrRetrieverService: cache hit', ['key' => $cacheKey]);
            return $cached;
        }

        $results = $this->runRetrieval($rawEmbedding, $userQuestion, $parsed);

        // On-demand fetch fallback if we found nothing locally
        if (empty($results)) {
            $fetchKey = md5('echr:' . $userQuestion);

            if (!$this->lock->isQueued($fetchKey) && !$this->lock->isLocked($fetchKey)) {
                $this->triggerOnDemandFetch($userQuestion, $parsed, $fetchKey);

                $released = $this->lock->waitUntilReleased($fetchKey, 8);
                if ($released) {
                    $results = $this->runRetrieval($rawEmbedding, $userQuestion, $parsed);
                }
            }
        }

        if (!empty($results)) {
            Cache::put($cacheKey, $results, self::CACHE_TTL_SECONDS);
        }

        return $results;
    }

    /**
     * Invalidate all ECHR retrieval caches by bumping the version counter.
     * Called by FetchHudocCaseJob after new cases are stored.
     */
    public static function invalidateCache(): void
    {
        Cache::increment(self::INDEX_VERSION_KEY);
    }

    // ── Retrieval pipeline ────────────────────────────────────────────────────

    private function runRetrieval(array $rawEmbedding, string $userQuestion, ParsedQuery $parsed): array
    {
        $cases = collect();

        // 1. Exact application number
        if ($parsed->echrApplicationNumber) {
            $case = $this->repo->findByApplicationNumber($parsed->echrApplicationNumber);
            if ($case) {
                $cases = collect([$case]);
            }
        }

        // 2. Article-based search
        if ($cases->isEmpty() && $parsed->echrArticle) {
            $cases = $this->repo->searchByArticle($parsed->echrArticle, self::TOP_K + 3);
        }

        // 3. Multi-term keyword search
        if ($cases->isEmpty()) {
            $terms = $this->extractSearchTerms($userQuestion);
            foreach ($terms as $term) {
                $found = $this->repo->searchByKeyword($term, self::TOP_K + 3);
                $cases = $cases->merge($found)->unique('id');
                if ($cases->count() >= self::TOP_K) {
                    break;
                }
            }
        }

        // 4. Vector search fallback
        if ($cases->isEmpty()) {
            $cases = $this->repo->vectorSearch($rawEmbedding, self::TOP_K + 3, self::VECTOR_THRESHOLD);
        } elseif ($cases->count() < self::TOP_K) {
            // Blend: add vector results for remaining slots
            $vectorCases = $this->repo->vectorSearch(
                $rawEmbedding,
                self::TOP_K - $cases->count() + 2,
                self::VECTOR_THRESHOLD
            );
            $cases = $cases->merge($vectorCases)->unique('id');
        }

        return $this->buildResults($cases->take(self::TOP_K));
    }

    private function buildResults(Collection $cases): array
    {
        return $cases->map(function (EchrCase $case) {
            $similarity = $case->best_similarity ?? 0.70;
            $excerpt    = $case->best_chunk ?? $case->excerpt ?? '';

            $articles = $case->relationLoaded('articles')
                ? $case->articles->pluck('article_code')->unique()->values()->toArray()
                : [];

            $effectiveDate = $case->judgment_date?->format('Y-m-d')
                          ?? $case->decision_date?->format('Y-m-d');

            return new EchrResult(
                caseId:            $case->id,
                hudocItemId:       $case->hudoc_itemid,
                applicationNumber: $case->application_number,
                title:             $case->title ?? '',
                judgmentDate:      $effectiveDate,
                documentType:      $case->document_type,
                importance:        $case->importance,
                echrArticles:      $articles,
                excerpt:           mb_substr($excerpt, 0, 1200),
                similarity:        $similarity,
                sourceUrl:         $case->source_url ?? "https://hudoc.echr.coe.int/eng#{\"itemid\":[\"{$case->hudoc_itemid}\"]}",
            );
        })->values()->toArray();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function triggerOnDemandFetch(string $userQuestion, ParsedQuery $parsed, string $fetchKey): void
    {
        $this->lock->markQueued($fetchKey);

        \App\Jobs\FetchHudocCaseJob::dispatch(
            query:        $userQuestion,
            echrArticle:  $parsed->echrArticle,
            georgiaFirst: $parsed->georgiaRelated ?? false,
            fetchKey:     $fetchKey,
        )->onQueue('default');

        Log::debug('EchrRetrieverService: dispatched on-demand fetch', [
            'query'        => $userQuestion,
            'echr_article' => $parsed->echrArticle,
        ]);
    }

    /**
     * Extract meaningful search terms, stripping Georgian stop words and task words.
     *
     * @return string[]
     */
    private function extractSearchTerms(string $question): array
    {
        $lower = mb_strtolower($question);

        // Remove ECHR-specific task words that are too generic
        $stop = [
            'echr', 'hudoc', 'strasbourg', 'european court of human rights',
            'convention', 'article', 'case', 'judgment', 'decision',
            'პრაქტიკა', 'საქმე', 'გადაწყვეტილება', 'სასამართლო',
        ];
        foreach ($stop as $s) {
            $lower = str_replace($s, '', $lower);
        }

        // Split and filter to meaningful tokens (4+ chars)
        $words = preg_split('/\s+/', trim($lower));
        $terms = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 4));

        if (empty($terms)) {
            return [mb_substr($question, 0, 60)];
        }

        // Return: full phrase first, then individual terms
        $phrase = implode(' ', array_slice($terms, 0, 4));
        return array_unique(array_merge([$phrase], $terms));
    }

    private function cacheKey(string $question, ParsedQuery $parsed): string
    {
        $version = (int) Cache::get(self::INDEX_VERSION_KEY, 0);
        $key     = $question . ($parsed->echrArticle ?? '') . ($parsed->echrTopic ?? '');
        return 'echr_retrieval:v' . $version . ':' . md5($key);
    }
}
