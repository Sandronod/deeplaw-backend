<?php

namespace App\Services\AI;

/**
 * Rule-based evidence pre-processor — no API calls.
 *
 * For each decision, finds the specific sentences/passages that are
 * most relevant to the user query. This gives the LLM explicit "evidence
 * anchors" so it doesn't need to hunt through large text blocks.
 *
 * Adds 'evidence_notes' and 'key_facts' to each decision array.
 * Used in buildContextBlock() of OpenAILegalAnswerService.
 */
class EvidenceBuilderService
{
    private const MIN_SENTENCE_LEN = 25;
    private const MAX_SENTENCE_LEN = 400;
    private const MAX_NOTES        = 5;
    private const MIN_TERM_LEN     = 3;

    /**
     * Enriches each decision with evidence notes extracted from the excerpt.
     *
     * @param  string  $query      Original user question
     * @param  array[] $decisions  Reconstructed decisions (each has excerpt, full_text, etc.)
     * @return array[] Same decisions with 'evidence_notes' added
     */
    public function build(string $query, array $decisions): array
    {
        if (empty($decisions)) {
            return $decisions;
        }

        $terms = $this->extractTerms($query);

        return array_map(function (array $d) use ($terms) {
            $text = $d['excerpt'] ?? mb_substr($d['full_text'] ?? '', 0, 4000);

            $d['evidence_notes'] = $this->findEvidenceNotes($text, $terms);
            $d['key_facts']      = $this->extractKeyFacts($d);

            return $d;
        }, $decisions);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Tokenizes query into searchable terms (length >= MIN_TERM_LEN).
     */
    private function extractTerms(string $query): array
    {
        $lower  = mb_strtolower(trim($query));
        $tokens = preg_split('/[\s,\.!?]+/u', $lower) ?: [];

        // Filter noise words and very short tokens
        $stopWords = ['და', 'ან', 'რომ', 'იყო', 'the', 'and', 'for', 'with', 'that'];
        $terms = array_filter($tokens, function (string $t) use ($stopWords) {
            return mb_strlen($t) >= self::MIN_TERM_LEN
                && !in_array($t, $stopWords, true);
        });

        return array_unique(array_values($terms));
    }

    /**
     * Finds sentences in text that contain query terms.
     * Returns at most MAX_NOTES unique sentences.
     */
    private function findEvidenceNotes(string $text, array $terms): array
    {
        if (empty($terms) || empty($text)) {
            return [];
        }

        // Split into sentences on Georgian and Latin sentence boundaries
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $notes     = [];
        $seen      = [];

        foreach ($terms as $term) {
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                $len      = mb_strlen($sentence);

                if ($len < self::MIN_SENTENCE_LEN || $len > self::MAX_SENTENCE_LEN) {
                    continue;
                }

                if (!str_contains(mb_strtolower($sentence), $term)) {
                    continue;
                }

                $hash = md5($sentence);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $notes[]     = $sentence;

                if (count($notes) >= self::MAX_NOTES) {
                    break 2;
                }
            }
        }

        return $notes;
    }

    /**
     * Extracts structured key facts from decision metadata.
     * These are always present regardless of query terms.
     */
    private function extractKeyFacts(array $d): array
    {
        $facts = [];

        if (!empty($d['result'])) {
            $facts[] = 'შედეგი: ' . $d['result'];
        }
        if (!empty($d['dispute_subject'])) {
            $facts[] = 'სადაო საგანი: ' . $d['dispute_subject'];
        }
        if (!empty($d['claim_type'])) {
            $facts[] = 'სარჩელის ტიპი: ' . $d['claim_type'];
        }
        if (!empty($d['category'])) {
            $facts[] = 'კატეგორია: ' . $d['category'];
        }

        return $facts;
    }
}
