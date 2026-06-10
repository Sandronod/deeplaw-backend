<?php

namespace App\Services\AI;

/**
 * Deterministic final-pass cleanup for recurring legal wording issues.
 *
 * This must stay cheap: no network calls, no embeddings, no database work.
 * It only normalizes high-confidence wording mistakes that validators already
 * know how to detect.
 */
class AnswerPostProcessorService
{
    /**
     * @param array<string, mixed> $ctx
     * @return array{text: string, changes: array<int, string>}
     */
    public function process(string $answerText, array $ctx = []): array
    {
        $changes = [];

        $answerText = $this->normalizeDefectNoticeTerminology($answerText, $changes);
        $answerText = $this->injectArticle55IfGrounded($answerText, $ctx['matsneResults'] ?? [], $changes);
        $answerText = $this->softenWeakCourtPracticeClaims(
            $answerText,
            $ctx['finalDecisions'] ?? [],
            $ctx['constCourtResults'] ?? [],
            $changes,
        );

        return [
            'text' => $answerText,
            'changes' => array_values(array_unique($changes)),
        ];
    }

    /**
     * @param array<int, string> $changes
     */
    private function normalizeDefectNoticeTerminology(string $text, array &$changes): string
    {
        $replacements = [
            'შეცილების ვადა და მოთხოვნის ხანდაზმულობა დაფარული ნაკლის შემთხვევაში'
                => 'პრეტენზიის/შეტყობინების ვადა და მოთხოვნის ხანდაზმულობა დაფარული ნაკლის შემთხვევაში',
            'შეცილების ვადა დაფარული ნაკლის გამო მოთხოვნის შემთხვევაში'
                => 'პრეტენზიის/შეტყობინების ვადა დაფარული ნაკლის გამო მოთხოვნის შემთხვევაში',
            'შეცილების ვადა დაფარული ნაკლის შემთხვევაში'
                => 'პრეტენზიის/შეტყობინების ვადა დაფარული ნაკლის შემთხვევაში',
            'შეცილების ვადა დაფარული ნაკლის გამო'
                => 'პრეტენზიის/შეტყობინების ვადა დაფარული ნაკლის გამო',
            'რა ვადაში უნდა გაასაჩივროს მყიდველმა ნაკლის გამო'
                => 'რა ვადაში უნდა წარადგინოს მყიდველმა პრეტენზია ნაკლის გამო',
        ];

        foreach ($replacements as $search => $replacement) {
            if (!str_contains($text, $search)) {
                continue;
            }

            $text = str_replace($search, $replacement, $text);
            $changes[] = 'normalized_defect_notice_terminology';
        }

        return $text;
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, string> $changes
     */
    private function injectArticle55IfGrounded(string $text, array $matsneResults, array &$changes): string
    {
        if (!$this->hasArticle($matsneResults, 55) || $this->mentionsArticle($text, 55)) {
            return $text;
        }

        $lower = mb_strtolower($text);
        if (!$this->containsAny($lower, ['ფასის დისპროპორც', 'ფასთა', 'ფასი', 'ღირებულ', '3-ჯერ', 'სამჯერ'])) {
            return $text;
        }

        $line = "  - სკ-ის 55-ე მუხლიც რელევანტურია ფასის/ანაზღაურების აშკარა შეუსაბამობის ნაწილში; ის ავტომატურ ბათილობას არ ნიშნავს და ჩვეულებრივ დამატებით გარემოებებს მოითხოვს.\n";

        $count = 0;
        $updated = preg_replace(
            '/(^\s*-\s+\*\*(?:Rule|📕 კანონი|კანონი\/ნორმა)\s*:\*\*[^\n]*54[^\n]*\n)/mu',
            '$1' . $line,
            $text,
            1,
            $count,
        );

        if ($count > 0 && is_string($updated)) {
            $changes[] = 'injected_grounded_article_55';

            return $updated;
        }

        return $text;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $constCourtResults
     * @param array<int, string> $changes
     */
    private function softenWeakCourtPracticeClaims(
        string $text,
        array $decisions,
        array $constCourtResults,
        array &$changes,
    ): string {
        if ($this->hasPrimaryDomesticAuthority($decisions)) {
            return $text;
        }

        if (empty($constCourtResults) && empty($decisions)) {
            return $text;
        }

        $replacements = [
            'სასამართლო პრაქტიკა არ აღიარებს მხოლოდ ფასის დისპროპორციას საკმარის საფუძვლად'
                => 'მოძიებული წყაროებით პირდაპირი სასამართლო პრაქტიკა ვერ მოიძებნა; დამხმარე სტანდარტით მხოლოდ ფასის დისპროპორცია საკმარის საფუძვლად არ ჩანს',
            'სასამართლოები ხაზს უსვამენ, რომ'
                => 'მოძიებული დამხმარე წყაროები მიუთითებს, რომ',
            'სასამართლოები მხოლოდ განსაკუთრებულ შემთხვევებში ცნობენ'
                => 'მოძიებული წყაროების მიხედვით, ასეთი დასკვნა მხოლოდ განსაკუთრებულ შემთხვევებში შეიძლება დადგეს',
        ];

        $updated = str_replace(array_keys($replacements), array_values($replacements), $text);
        if ($updated !== $text) {
            $changes[] = 'softened_non_primary_court_practice_claim';
        }

        return $updated;
    }

    /**
     * @param array<int, array<string, mixed>> $docs
     */
    private function hasArticle(array $docs, int $article): bool
    {
        foreach ($docs as $doc) {
            foreach (['_article_num', 'article_num'] as $key) {
                if ((int) ($doc[$key] ?? 0) === $article) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mentionsArticle(string $text, int $article): bool
    {
        return (bool) preg_match('/(?:სკ(?:-ის)?\s*)?' . $article . '(?:-ე)?\s+მუხლ|მუხლი\s+' . $article . '/u', $text);
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     */
    private function hasPrimaryDomesticAuthority(array $decisions): bool
    {
        foreach ($decisions as $decision) {
            if (($decision['answer_role'] ?? null) === 'primary'
                && !in_array('weak_context_match', $decision['quality_flags'] ?? [], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
