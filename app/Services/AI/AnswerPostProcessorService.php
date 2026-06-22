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
        $answerText = $this->removeIrrelevantArticle55Mentions($answerText, $ctx['userQuestion'] ?? '', $changes);
        $answerText = $this->correctPersonalDataLawNotFoundClaim($answerText, $ctx['matsneResults'] ?? [], $changes);
        $answerText = $this->softenPersonalDataFineTransferClaim($answerText, $ctx['matsneResults'] ?? [], $changes);
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
     * @param array<int, string> $changes
     */
    private function removeIrrelevantArticle55Mentions(string $text, string $question, array &$changes): string
    {
        if (!$this->mentionsArticle($text, 55)) {
            return $text;
        }

        $questionLower = mb_strtolower($question);
        if ($this->containsAny($questionLower, [
            'დისპროპორც',
            'შეუსაბამ',
            'ამორალ',
            'ზნეობ',
            'საბაზრო ღირებულ',
            'ფასი',
            'ღირებულება',
        ])) {
            return $text;
        }

        $original = $text;
        $lines = preg_split('/\R/u', $text) ?: [$text];
        $kept = [];

        foreach ($lines as $line) {
            $lower = mb_strtolower($line);
            if ($this->containsAny($lower, ['მუხლი 55', '55-ე მუხლ', 'სკ-ის 55', 'სკ 55'])) {
                continue;
            }

            $kept[] = $line;
        }

        $text = implode("\n", $kept);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        if ($text !== $original) {
            $changes[] = 'removed_irrelevant_article_55';
        }

        return $text;
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, string> $changes
     */
    private function correctPersonalDataLawNotFoundClaim(string $text, array $matsneResults, array &$changes): string
    {
        if (!$this->hasPersonalDataLaw($matsneResults)) {
            return $text;
        }

        $updated = $text;

        $updated = preg_replace_callback(
            '/^(\s*-\s*\*\*(?:კანონი|წყარო):\*\*\s*)[^\n]*(?:პერსონალურ მონაცემთა დაცვის შესახებ|მონაცემთა დაცვის შესახებ)[^\n]*(?:არ\s+(?:მოიძებნა|იძებნება|არის\s+დადასტურებული|არის\s+პირდაპირ\s+დადასტურებული)|მუხლები\s+მოძიებული\s+არ\s+არის|მუხლი\s+მოძიებული\s+არ\s+არის|„„|\);\s*შრომის\s+კოდექსი,\s*მუხლი\s*60)[^\n]*$/mu',
            fn (array $matches): string => $matches[1] . $this->personalDataLawText($matsneResults),
            $updated,
        ) ?? $updated;

        $updated = preg_replace(
            '/^\s*-\s*(?!\*\*(?:კანონი|წყარო):\*\*)[^\n]*(?:პერსონალურ მონაცემთა დაცვის შესახებ|მონაცემთა დაცვის შესახებ)[^\n]*(?:არ\s+(?:იძებნება|მოიძებნა|არის\s+დადასტურებული|არის\s+პირდაპირ\s+დადასტურებული)|მუხლები\s+მოძიებული\s+არ\s+არის|მუხლი\s+მოძიებული\s+არ\s+არის)[^\n]*$/mu',
            '- ' . $this->personalDataSourceText($matsneResults),
            $updated,
        ) ?? $updated;

        $updated = preg_replace(
            '/(?:სპეციალური ნორმა\s+)?პერსონალურ მონაცემთა დაცვის შესახებ(?:\s+კანონის მიხედვით)?[^.\n]{0,220}(?:არ მოიძებნა|არ იძებნება|არ არის დადასტურებული|არ არის პირდაპირ დადასტურებული|მუხლები მოძიებული არ არის|მუხლი მოძიებული არ არის)\.?/u',
            $this->personalDataLawText($matsneResults),
            $updated,
        ) ?? $updated;

        if ($updated !== $text) {
            $changes[] = 'corrected_personal_data_law_not_found_claim';
        }

        return $updated;
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     */
    private function personalDataLawText(array $matsneResults = []): string
    {
        $articleText = $this->personalDataArticleText($matsneResults);

        return '„პერსონალურ მონაცემთა დაცვის შესახებ“ კანონი' . $articleText . ' არის სპეციალური წყარო: მონაცემთა გაჟონვის საკითხზე უნდა შეფასდეს მონაცემთა დამმუშავებელი/უფლებამოსილი პირი, მონაცემთა უსაფრთხოების ვალდებულება, საზედამხედველო ღონისძიებები და ადმინისტრაციული პასუხისმგებლობა.';
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     */
    private function personalDataSourceText(array $matsneResults = []): string
    {
        return '„პერსონალურ მონაცემთა დაცვის შესახებ“ კანონი' . $this->personalDataArticleText($matsneResults) . ' — გამოიყენება მონაცემთა უსაფრთხოების, დამმუშავებლის/უფლებამოსილი პირის და ადმინისტრაციული პასუხისმგებლობის საკითხებზე.';
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     */
    private function personalDataArticleText(array $matsneResults): string
    {
        $articles = [];

        foreach ($matsneResults as $result) {
            $title = mb_strtolower((string) ($result['title'] ?? ''));
            $text = mb_strtolower((string) ($result['excerpt'] ?? '') . "\n" . (string) ($result['content'] ?? ''));

            if (!str_contains($title, 'პერსონალურ მონაცემთა დაცვის შესახებ')
                && !str_contains($text, 'პერსონალურ მონაცემთა დაცვის შესახებ')
            ) {
                continue;
            }

            foreach (['_article_num', 'article_num'] as $key) {
                if (isset($result[$key]) && preg_match('/\d{1,4}/', (string) $result[$key], $match)) {
                    $articles[] = (int) $match[0];
                }
            }
        }

        $articles = array_values(array_unique(array_filter($articles)));
        sort($articles);

        return empty($articles)
            ? ''
            : ', მუხლები ' . implode(', ', $articles);
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, string> $changes
     */
    private function softenPersonalDataFineTransferClaim(string $text, array $matsneResults, array &$changes): string
    {
        if (!$this->hasPersonalDataLaw($matsneResults)) {
            return $text;
        }

        $updated = preg_replace(
            '/ადმინისტრაციული\s+ჯარიმის\s+დაკისრება\s+შესაძლებელია\s+მხოლოდ\s+იურიდიულ\s+პირზე/u',
            'ადმინისტრაციული ჯარიმის ადრესატი განისაზღვრება „პერსონალურ მონაცემთა დაცვის შესახებ“ კანონით; თანამშრომელზე მისი ავტომატური გადაკისრება დაუშვებელია და ცალკე რეგრესულ/მატერიალური პასუხისმგებლობის საფუძველს მოითხოვს',
            $text,
        ) ?? $text;

        if ($updated !== $text) {
            $changes[] = 'softened_personal_data_fine_transfer_claim';
        }

        return $updated;
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
     * @param array<int, array<string, mixed>> $docs
     */
    private function hasPersonalDataLaw(array $docs): bool
    {
        foreach ($docs as $doc) {
            $title = mb_strtolower((string) ($doc['title'] ?? ''));
            $text = mb_strtolower((string) ($doc['excerpt'] ?? '') . "\n" . (string) ($doc['content'] ?? ''));

            if (str_contains($title, 'პერსონალურ მონაცემთა დაცვის შესახებ')
                || str_contains($text, 'პერსონალურ მონაცემთა დაცვის შესახებ')
            ) {
                return true;
            }
        }

        return false;
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
