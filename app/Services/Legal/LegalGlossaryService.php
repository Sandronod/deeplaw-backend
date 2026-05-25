<?php

namespace App\Services\Legal;

use Illuminate\Support\Facades\Cache;

/**
 * Loads and serves the Georgian legal glossary.
 *
 * Source file: storage/app/glossary_enriched.json  (after glossary:enrich)
 * Fallback:    storage/app/glossary_raw.json        (after glossary:extract)
 *
 * Two main jobs:
 *   1. findTerms(string $text)  → returns all glossary entries whose term
 *      appears in the given text (for system prompt injection)
 *
 *   2. expandQuery(string $query) → returns extra search terms (synonyms)
 *      found in the query, for QueryExtractor use
 */
class LegalGlossaryService
{
    private const CACHE_KEY = 'legal_glossary_v1';
    private const CACHE_TTL = 3600; // 1 hour

    /** @var array<string, array> term → entry */
    private array $glossary = [];

    /** @var bool */
    private bool $loaded = false;

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->glossary = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $path = storage_path('app/glossary_enriched.json');
            if (!file_exists($path)) {
                $path = storage_path('app/glossary_raw.json');
            }
            if (!file_exists($path)) {
                return [];
            }

            $data    = json_decode(file_get_contents($path), true) ?? [];
            $indexed = [];

            foreach ($data as $entry) {
                $term = $entry['term'] ?? null;
                if (!$term) continue;

                // Index by canonical term
                $indexed[mb_strtolower($term)] = $entry;

                // Also index synonyms → same entry
                foreach ($entry['synonyms_ka'] ?? [] as $syn) {
                    $synLower = mb_strtolower($syn);
                    if (!isset($indexed[$synLower])) {
                        $indexed[$synLower] = $entry;
                    }
                }
            }

            return $indexed;
        });

        $this->loaded = true;
    }

    /**
     * Find all glossary entries whose terms appear in $text.
     * Returns deduplicated entries (by canonical term), sorted by count desc.
     *
     * @return array[]
     */
    public function findTerms(string $text): array
    {
        $this->load();

        if (empty($this->glossary)) {
            return [];
        }

        $lower = mb_strtolower($text);
        $found = [];   // canonical_term → entry

        foreach ($this->glossary as $term => $entry) {
            if (str_contains($lower, $term)) {
                $canonical = mb_strtolower($entry['term'] ?? $term);
                $found[$canonical] = $entry;
            }
        }

        // Sort by frequency (most common terms first → most relevant to include)
        uasort($found, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        return array_values($found);
    }

    /**
     * Returns extra search terms (synonyms_ka) for terms found in the query.
     * Used by QueryExtractorService to expand search coverage.
     *
     * @return string[]
     */
    public function expandQuery(string $query): array
    {
        $entries = $this->findTerms($query);
        $extra   = [];

        foreach ($entries as $entry) {
            foreach ($entry['synonyms_ka'] ?? [] as $syn) {
                if (!str_contains(mb_strtolower($query), mb_strtolower($syn))) {
                    $extra[] = $syn;
                }
            }
        }

        return array_values(array_unique($extra));
    }

    /**
     * Builds a compact glossary block for LLM system prompts.
     * Only includes terms found in the given text, max $limit entries.
     * Skips entries without ka_definition.
     */
    public function buildPromptBlock(string $text, int $limit = 10): string
    {
        $entries = $this->findTerms($text);

        $lines = [];
        foreach ($entries as $entry) {
            if (empty($entry['ka_definition'])) {
                continue;
            }

            $term   = $entry['term'];
            $kaDef  = $entry['ka_definition'];
            $enNote = $entry['en_note'] ? " [{$entry['en_note']}]" : '';
            $lines[] = "• {$term}: {$kaDef}{$enNote}";

            if (count($lines) >= $limit) {
                break;
            }
        }

        if (empty($lines)) {
            return '';
        }

        return "GEORGIAN LEGAL TERMINOLOGY:\n" . implode("\n", $lines);
    }

    /**
     * Returns total number of glossary entries loaded.
     */
    public function count(): int
    {
        $this->load();
        return count($this->glossary);
    }

    /**
     * Clear the cache (call after glossary:enrich completes).
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded   = false;
        $this->glossary = [];
    }
}
