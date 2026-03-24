<?php

namespace App\Services\AI;

use App\DTOs\ConfidenceResult;
use App\DTOs\RetrievalResult;

/**
 * Multi-factor confidence assessment — no API calls.
 *
 * Composite score formula (0–1):
 *   0.50 × max_similarity       — best single match quality
 *   0.25 × avg_similarity       — overall retrieval quality
 *   0.10 × case_count_factor    — more cases = more confidence (saturates at 3)
 *   0.10 × chunk_count_factor   — more matched chunks = more confidence (saturates at 10)
 *   0.05 × consistency_factor   — low score variance = consistent retrieval
 *
 * Label thresholds:
 *   high   — composite >= 0.70 AND max >= 0.65
 *   medium — composite >= 0.50 OR  max >= 0.50
 *   low    — anything above zero
 *   none   — no results
 */
class ConfidenceAssessor
{
    public function assess(RetrievalResult $retrieval): ConfidenceResult
    {
        if ($retrieval->isEmpty()) {
            return new ConfidenceResult(
                score:       0.0,
                label:       'none',
                explanation: 'ბაზაში შესაბამისი გადაწყვეტილება ვერ მოიძებნა.',
            );
        }

        $scores = array_values($retrieval->relevanceScores);

        if (empty($scores)) {
            return new ConfidenceResult(
                score:       0.10,
                label:       'low',
                explanation: 'მხოლოდ მეტადეიტა match — ვექტორული სიახლოვე ვერ გამოითვალა.',
            );
        }

        $maxScore  = max($scores);
        $avgScore  = array_sum($scores) / count($scores);
        $variance  = $this->variance($scores);

        $caseCount  = $retrieval->usedCaseCount;
        $chunkCount = $retrieval->usedChunkCount;

        $caseFactor      = min(1.0, $caseCount  / 3.0);
        $chunkFactor     = min(1.0, $chunkCount / 10.0);
        $consistencyFactor = max(0.0, 1.0 - min(1.0, $variance * 10.0));

        $composite = (0.50 * $maxScore)
                   + (0.25 * $avgScore)
                   + (0.10 * $caseFactor)
                   + (0.10 * $chunkFactor)
                   + (0.05 * $consistencyFactor);

        $composite = round(min(1.0, max(0.0, $composite)), 4);

        [$label, $explanation] = $this->resolveLabel(
            $composite,
            $maxScore,
            $avgScore,
            $caseCount,
            $chunkCount,
            $variance,
        );

        return new ConfidenceResult(
            score:       $composite,
            label:       $label,
            explanation: $explanation,
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveLabel(
        float $composite,
        float $maxScore,
        float $avgScore,
        int   $caseCount,
        int   $chunkCount,
        float $variance,
    ): array {
        $scoreNote = sprintf(
            'max=%.2f avg=%.2f cases=%d chunks=%d variance=%.3f',
            $maxScore, $avgScore, $caseCount, $chunkCount, $variance
        );

        if ($composite >= 0.70 && $maxScore >= 0.65) {
            return [
                'high',
                "პირდაპირი შესაბამისობა. {$caseCount} საქმე, {$chunkCount} ფრაგმენტი. [{$scoreNote}]",
            ];
        }

        if ($composite >= 0.50 || ($maxScore >= 0.50 && $caseCount >= 1)) {
            return [
                'medium',
                "ნაწილობრივი შესაბამისობა. {$caseCount} საქმე. [{$scoreNote}]",
            ];
        }

        if ($composite > 0.0) {
            return [
                'low',
                "სუსტი კავშირი — შედეგები მხოლოდ ნაწილობრივ შეესაბამება. [{$scoreNote}]",
            ];
        }

        return [
            'none',
            'ვექტორული სიახლოვე ძალიან დაბალია.',
        ];
    }

    private function variance(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean    = array_sum($values) / $n;
        $sqDiffs = array_map(fn($v) => ($v - $mean) ** 2, $values);
        return array_sum($sqDiffs) / $n;
    }

    /**
     * Human-readable Georgian label (for logging / legacy callers).
     */
    public function label(string $confidence): string
    {
        return match ($confidence) {
            'high'   => 'მაღალი სანდოობა',
            'medium' => 'საშუალო სანდოობა',
            'low'    => 'დაბალი სანდოობა',
            'none'   => 'შედეგი არ მოიძებნა',
            default  => 'უცნობი',
        };
    }
}
