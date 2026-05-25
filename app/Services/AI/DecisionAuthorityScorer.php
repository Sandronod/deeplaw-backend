<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * გადაწყვეტილებებს ანიჭებს სამართლებრივ ავტორიტეტის ქულას:
 *
 *  1. court level   — უზენაეს=800, სააპელ.=400, სხვა=200
 *  2. year recency  — (year - 2010) * 25, max 350
 *  3. joint panel   — "გაერთ." chamber-ში = +300
 *
 * combined_score = 0.6 × relevance + 0.4 × (authority / MAX_AUTHORITY)
 *
 * outlier detection — majority result ამოყოფს შეუსაბამო გადაწყვეტილებებს
 * trend detection   — 2010-2019 vs 2020+ პრაქტიკის ცვლილება
 */
class DecisionAuthorityScorer
{
    private const MAX_AUTHORITY = 1450; // 800 + 350 + 300

    private const WEIGHT_RELEVANCE  = 0.6;
    private const WEIGHT_AUTHORITY  = 0.4;

    private const OUTLIER_THRESHOLD = 0.20; // < 20% = outlier
    private const OUTLIER_PENALTY   = 0.70; // ×0.7 score penalty

    private const YEAR_BASE   = 2010;
    private const YEAR_FACTOR = 25;
    private const YEAR_MAX    = 350;

    /**
     * @param  array[] $decisions  Reconstructed decisions from retriever
     * @param  string  $mode       'advocate' skips outlier penalty and promotes minority opinions
     * @return array[] Same array, enriched with authority_score, combined_score, flags
     */
    public function score(array $decisions, string $mode = 'explain'): array
    {
        if (empty($decisions)) {
            return $decisions;
        }

        // ── 1. Authority score ყოველ გადაწყვეტილებაზე ────────────────────────
        foreach ($decisions as &$d) {
            $authority = $this->calcAuthority($d);
            $d['authority_score']   = $authority;
            $d['authority_details'] = $this->authorityBreakdown($d);
        }
        unset($d);

        // ── 2. Outlier detection ──────────────────────────────────────────────
        $decisions = $this->detectOutliers($decisions);

        // ── 3. Trend detection ────────────────────────────────────────────────
        $decisions = $this->detectTrend($decisions);

        // ── 4. Combined score ─────────────────────────────────────────────────
        $isAdvocate = $mode === 'advocate';

        foreach ($decisions as &$d) {
            $relevance = (float) ($d['relevance_score'] ?? 0.5);
            $authority = (float) ($d['authority_score'] ?? 0);
            $normalizedAuth = $authority / self::MAX_AUTHORITY;

            $combined = self::WEIGHT_RELEVANCE * $relevance
                      + self::WEIGHT_AUTHORITY * $normalizedAuth;

            $isOutlier = in_array('outlier', $d['quality_flags'] ?? []);

            if ($isOutlier && $isAdvocate) {
                // advocate mode: outlier = valuable minority opinion — არ დავჯარიმოთ
                $d['quality_flags'][] = 'advocate_value';
            } elseif ($isOutlier) {
                // normal mode: outlier penalty
                $combined *= self::OUTLIER_PENALTY;
            }

            $d['combined_score'] = round($combined, 4);
        }
        unset($d);

        // ── 5. Re-sort by combined score ─────────────────────────────────────
        usort($decisions, fn($a, $b) =>
            (float) $b['combined_score'] <=> (float) $a['combined_score']
        );

        Log::debug('DecisionAuthorityScorer: scored', [
            'count'    => count($decisions),
            'top_court' => $decisions[0]['court'] ?? null,
            'top_score' => $decisions[0]['combined_score'] ?? null,
        ]);

        return $decisions;
    }

    // ── Authority calculation ─────────────────────────────────────────────────

    private function calcAuthority(array $d): float
    {
        return $this->courtScore($d['court'] ?? '')
             + $this->yearScore($d['case_date'] ?? null)
             + $this->jointBonus($d['chamber'] ?? '');
    }

    private function courtScore(string $court): float
    {
        return match (true) {
            str_contains($court, 'უზენაეს')     => 800,
            str_contains($court, 'სააპელაციო')  => 400,
            default                              => 200,
        };
    }

    private function yearScore(mixed $date): float
    {
        if (!$date) {
            return 0;
        }

        $dateStr = $date instanceof \Carbon\Carbon
            ? $date->format('Y')
            : (is_string($date) ? substr((string) $date, 0, 4) : null);

        $year = $dateStr ? (int) $dateStr : 0;
        if ($year < self::YEAR_BASE || $year > (int) date('Y')) {
            return 0;
        }

        return (float) min(self::YEAR_MAX, ($year - self::YEAR_BASE) * self::YEAR_FACTOR);
    }

    private function jointBonus(string $chamber): float
    {
        return (str_contains($chamber, 'გაერთ') || str_contains($chamber, 'სრული')) ? 300 : 0;
    }

    private function authorityBreakdown(array $d): array
    {
        return [
            'court_score' => $this->courtScore($d['court'] ?? ''),
            'year_score'  => $this->yearScore($d['case_date'] ?? null),
            'joint_bonus' => $this->jointBonus($d['chamber'] ?? ''),
            'total'       => $this->calcAuthority($d),
        ];
    }

    // ── Outlier detection ─────────────────────────────────────────────────────

    /**
     * თუ N≥4 გადაწყვეტილებაა და ერთ-ერთის result < 20%-ია — outlier.
     */
    private function detectOutliers(array $decisions): array
    {
        if (count($decisions) < 4) {
            return $decisions;
        }

        // result-ების სიხშირე
        $resultCounts = [];
        foreach ($decisions as $d) {
            $result = $this->normalizeResult($d['result'] ?? '');
            if ($result) {
                $resultCounts[$result] = ($resultCounts[$result] ?? 0) + 1;
            }
        }

        if (empty($resultCounts)) {
            return $decisions;
        }

        $total = array_sum($resultCounts);

        foreach ($decisions as &$d) {
            $result = $this->normalizeResult($d['result'] ?? '');
            if (!$result) {
                continue;
            }

            $share = ($resultCounts[$result] ?? 0) / $total;
            if ($share < self::OUTLIER_THRESHOLD) {
                $d['quality_flags']   = array_merge($d['quality_flags'] ?? [], ['outlier']);
                $d['outlier_note']    = "minority result ({$resultCounts[$result]}/{$total} cases)";
            }
        }
        unset($d);

        return $decisions;
    }

    /**
     * "დაკმაყოფილდა", "დაკმაყოფილდა ნაწილობრივ" → "granted"
     * "უარყოფილ იქნა" → "denied"
     */
    private function normalizeResult(string $result): string
    {
        $lower = mb_strtolower(trim($result));

        if (str_contains($lower, 'დაკმაყოფ')) {
            return 'granted';
        }
        if (str_contains($lower, 'უარყოფ') || str_contains($lower, 'არ დაკმ')) {
            return 'denied';
        }
        if (str_contains($lower, 'შეწყდ') || str_contains($lower, 'შეჩერდ')) {
            return 'stopped';
        }

        return '';
    }

    // ── Trend detection ───────────────────────────────────────────────────────

    /**
     * ადარებს 2010-2019 და 2020+ პრაქტიკას.
     * თუ განსხვავება 30%+ — trend flag ემატება ახლო საქმეებს.
     */
    private function detectTrend(array $decisions): array
    {
        $old = ['granted' => 0, 'denied' => 0];
        $new = ['granted' => 0, 'denied' => 0];

        foreach ($decisions as $d) {
            $year   = $this->extractYear($d['case_date'] ?? null);
            $result = $this->normalizeResult($d['result'] ?? '');

            if (!$result || !in_array($result, ['granted', 'denied'])) {
                continue;
            }

            if ($year >= 2020) {
                $new[$result]++;
            } elseif ($year >= 2010) {
                $old[$result]++;
            }
        }

        $oldTotal = array_sum($old);
        $newTotal = array_sum($new);

        if ($oldTotal < 2 || $newTotal < 2) {
            return $decisions;
        }

        $oldGrantRate = $old['granted'] / $oldTotal;
        $newGrantRate = $new['granted'] / $newTotal;
        $shift        = $newGrantRate - $oldGrantRate;

        if (abs($shift) < 0.30) {
            return $decisions;
        }

        $direction = $shift > 0 ? 'plaintiff_favorable' : 'defendant_favorable';
        $trendNote = sprintf(
            'trend: %s (2010-2019: %.0f%% granted → 2020+: %.0f%% granted)',
            $direction,
            $oldGrantRate * 100,
            $newGrantRate * 100,
        );

        // ახლო საქმეებს (2020+) trend note ვუმატებ
        foreach ($decisions as &$d) {
            $year = $this->extractYear($d['case_date'] ?? null);
            if ($year >= 2020) {
                $d['trend_note'] = $trendNote;
                $d['quality_flags'] = array_merge($d['quality_flags'] ?? [], ['trend_shift']);
            }
        }
        unset($d);

        Log::debug('DecisionAuthorityScorer: trend detected', [
            'shift'     => round($shift, 2),
            'direction' => $direction,
            'old_rate'  => round($oldGrantRate, 2),
            'new_rate'  => round($newGrantRate, 2),
        ]);

        return $decisions;
    }

    private function extractYear(mixed $date): int
    {
        if (!$date) {
            return 0;
        }
        if ($date instanceof \Carbon\Carbon) {
            return $date->year;
        }
        return (int) substr((string) $date, 0, 4);
    }
}
