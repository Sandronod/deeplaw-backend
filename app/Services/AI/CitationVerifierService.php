<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * Citation Verifier — ამოწმებს LLM-ის პასუხში ნახსენები საქმის ნომრები
 * და მაცნეს დოკუმენტები retrieved source list-ში არსებობს თუ არა.
 *
 * ორი ფუნქცია:
 *   1. verify()                  → post-generation check (meta-ში ინახება)
 *   2. buildVerifiedSourcesBlock() → pre-generation prompt block (LLM-ს ვეუბნებით რომელი ნომრები აქვს)
 */
class CitationVerifierService
{
    /**
     * ქართული სასამართლო საქმის ნომრის პატერნი.
     *
     * ფარავს:
     *   ას-100-20, სს/01-1234, ბს-1-1234-20, ბს-100/2023, 3/0-20
     *   AP-100/2023 (Latin prefix)
     */
    // Requires at least TWO numeric segments (e.g. ას-870-2024, ბს-1-1234-20)
    // This intentionally excludes Georgian ordinal forms like "მე-9" (single segment).
    private const CASE_NUM_PATTERN = '/\b[ა-ჰA-Z]{1,5}[-\/]\d+(?:[-\/]\d+)+(?:\([^)]+\))?\b/u';

    /**
     * პასუხის ტექსტიდან ამოიღებს case ნომრებს და ადარებს retrieved list-ს.
     *
     * @param  string $answerText         LLM-ის გენერირებული პასუხი
     * @param  array  $retrievedDecisions finalize()-ში გადაცემული decisions
     * @param  array  $matsneResults      finalize()-ში გადაცემული matsne docs
     * @return array{
     *   valid: string[],
     *   hallucinated: string[],
     *   flagged: bool,
     *   extracted_count: int,
     *   valid_count: int,
     *   hallucinated_count: int,
     * }
     */
    public function verify(
        string $answerText,
        array  $retrievedDecisions,
        array  $matsneResults = [],
    ): array {
        // 1. retrieved case ნომრების whitelist
        $validNums = collect($retrievedDecisions)
            ->pluck('case_num')
            ->filter()
            ->map(fn($n) => $this->normalize($n))
            ->toArray();

        // 2. პასუხიდან ამოვიღოთ case ნომრები
        $found = $this->extractCaseNumbers($answerText);

        $valid        = [];
        $hallucinated = [];

        foreach ($found as $num) {
            if (in_array($this->normalize($num), $validNums, true)) {
                $valid[] = $num;
            } else {
                $hallucinated[] = $num;
            }
        }

        $valid        = array_values(array_unique($valid));
        $hallucinated = array_values(array_unique($hallucinated));
        $flagged      = !empty($hallucinated);

        if ($flagged) {
            Log::warning('CitationVerifier: hallucinated case numbers detected', [
                'hallucinated' => $hallucinated,
                'valid'        => $valid,
                'valid_pool'   => $validNums,
            ]);
        }

        return [
            'valid'              => $valid,
            'hallucinated'       => $hallucinated,
            'flagged'            => $flagged,
            'extracted_count'    => count($found),
            'valid_count'        => count($valid),
            'hallucinated_count' => count($hallucinated),
        ];
    }

    /**
     * prompt-ის დასაწყისში ჩასასმელი "whitelist" block.
     * LLM-ს ეუბნება: "მხოლოდ ეს ნომრები შეიძლება ციტირდეს".
     */
    public function buildVerifiedSourcesBlock(array $decisions, array $matsneResults = []): string
    {
        $caseNums = collect($decisions)
            ->pluck('case_num')
            ->filter()
            ->values()
            ->toArray();

        if (empty($caseNums) && empty($matsneResults)) {
            return '';
        }

        $lines = ["────────────────────────\n🔒 PERMITTED CITATIONS (STRICT)\n────────────────────────"];
        $lines[] = "შეგიძლია მხოლოდ შემდეგი წყაროები დაიციტირო. სხვა არაფერი.\n";

        if (!empty($caseNums)) {
            $lines[] = "✅ სასამართლო გადაწყვეტილებები:";
            foreach ($caseNums as $num) {
                $lines[] = "   • {$num}";
            }
        }

        if (!empty($matsneResults)) {
            $lines[] = "\n✅ მაცნეს/კანონმდებლობის დოკუმენტები:";
            foreach ($matsneResults as $doc) {
                $title = mb_substr($doc['title'] ?? '', 0, 80);
                $lines[] = "   • {$title}";
            }
        }

        $lines[] = "\n❌ ABSOLUTE RULE: ზემოთ ჩამოთვლილის გარდა სხვა case ნომრის ან კანონის ციტირება = FABRICATION.";
        $lines[] = "❌ თუ CONTEXT-ში მოძებნილი საქმე/კანონი ამ სიაში არ არის — ვერ ციტირებ.";
        $lines[] = "❌ training data-ს case ნომრები არ გამოიყენო — ბაზიდან მოძიებული ნომრებიც შეიძლება შეცვლილი იყოს.";
        $lines[] = "────────────────────────";

        return implode("\n", $lines);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function extractCaseNumbers(string $text): array
    {
        preg_match_all(self::CASE_NUM_PATTERN, $text, $matches);
        return $matches[0] ?? [];
    }

    private function normalize(string $caseNum): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', '', $caseNum)));
    }
}
