<?php

namespace App\Services\AI;

use App\DTOs\TriageResult;

/**
 * Builds a source-grounded doctrine/remedy frame for the answer prompt.
 *
 * The goal is not to hardcode one casus, but to keep legal consequences
 * separated: nullity, avoidance, termination, price reduction, damages,
 * limitation periods, and procedural bars are different outcomes.
 */
class LegalRemedyGuardService
{
    private const OUTCOME_KEYWORDS = [
        'invalidity' => [
            'label' => 'ბათილობა / არარა შედეგი',
            'needles' => ['ბათილ', 'არარა', 'ნამდვილი არ არის'],
        ],
        'avoidance' => [
            'label' => 'შეცილება / მოტყუებით დადებული გარიგება',
            'needles' => ['შეცილ', 'მოტყუ', 'სადავო გახდეს', 'ბათილობის მოთხოვნა'],
        ],
        'termination' => [
            'label' => 'ხელშეკრულების მოშლა / შეწყვეტა',
            'needles' => ['მოშლ', 'შეწყვეტ'],
        ],
        'cure_or_replacement' => [
            'label' => 'ნაკლის გამოსწორება / ნივთის შეცვლა',
            'needles' => ['გამოასწორ', 'გამოსწორ', 'შეცვალ', 'შეცვლა'],
        ],
        'price_reduction' => [
            'label' => 'ფასის შემცირება',
            'needles' => ['ფასის შემცირ', 'შემცირება'],
        ],
        'damages' => [
            'label' => 'ზიანის ანაზღაურება',
            'needles' => ['ზიან', 'ანაზღაურ'],
        ],
        'notice_or_preclusion' => [
            'label' => 'შეტყობინება / პრეტენზია / უფლების დაკარგვა',
            'needles' => ['პრეტენზ', 'აცნობ', 'ეცნობ', 'ერთმევა', 'უფლების დაკარგ'],
        ],
        'limitation' => [
            'label' => 'ხანდაზმულობა / ვადა',
            'needles' => ['ხანდაზმულ', 'ვადა', 'ვადის', 'წლის', 'თვე'],
        ],
    ];

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $decisions
     */
    public function buildPromptBlock(
        string $question,
        array $matsneResults = [],
        array $decisions = [],
        ?TriageResult $triage = null,
    ): string {
        if (($triage?->isChatOnly()) === true) {
            return '';
        }

        $questionOutcomes = $this->detectOutcomes($question);
        $sourceRows = $this->sourceOutcomeRows($matsneResults);
        $hasDefectRemedySources = $this->hasDefectRemedySources($matsneResults);
        $hasDefectNoticeSources = $this->hasDefectNoticeSources($matsneResults);
        $hasWeakCases = $this->hasWeakCourtSources($decisions);

        if (empty($questionOutcomes) && empty($sourceRows) && !$hasDefectRemedySources && !$hasDefectNoticeSources && !$hasWeakCases) {
            return '';
        }

        $lines = [
            '────────────────────────',
            '🧭 LEGAL OUTCOME / REMEDY GUARD (source-grounded)',
            '────────────────────────',
            'სავალდებულო წესები:',
            '1. ერთმანეთისგან გამიჯნე სამართლებრივი საფუძველი და სამართლებრივი შედეგი.',
            '2. არ გადაარქვა ერთი remedy მეორედ: მოშლა ≠ ბათილობა; შეცილება ≠ ავტომატური ბათილობა; ფასის შემცირება ≠ ზიანის ანაზღაურება.',
            '3. თუ ნორმა იძლევა კონკრეტულ დაცვის საშუალებას, პასუხში სწორედ ის დაასახელე. არ გააფართოო შედეგი წყაროს გარეშე.',
            '4. თუ რამდენიმე რეჟიმი იკვეთება, გაანალიზე ცალ-ცალკე: ბათილობა/შეცილება, ნაკლის remedies, ხანდაზმულობა, ზიანი.',
        ];

        if ($hasDefectRemedySources) {
            $lines[] = '5. DEFECT REMEDY RULE: თუ წყაროებში ნივთის/დაფარული ნაკლი უკავშირდება მოშლას, ნაკლის გამოსწორებას, ფასის შემცირებას ან ზიანს, საბოლოო დასკვნაში არ დაწერო "ბათილობა ნაკლის საფუძველზე". სწორი ფორმულაა: "ნაკლი → მოშლა/ფასის შემცირება/გამოსწორება/ზიანი"; ბათილობა განიხილე მხოლოდ დამოუკიდებელი საფუძვლით, მაგალითად მოტყუება ან სკ-ის 54-ე მუხლი.';
            $lines[] = 'BAD: "ბათილობის მოთხოვნა საფუძვლიანია ნაკლის (სკ-ის 491) საფუძვლით."';
            $lines[] = 'GOOD: "ნაკლი სკ-ის 491-ის მიხედვით იძლევა მოშლის და სხვა სპეციალურ remedies-ს; ბათილობა შეიძლება განიხილებოდეს მხოლოდ მოტყუების/54-ე მუხლის დამოუკიდებელი საფუძვლით."';
        }

        if ($hasDefectNoticeSources) {
            $lines[] = '6. NOTICE / PRECLUSION RULE: ნივთის ნაკლთან დაკავშირებული პრეტენზიის/შეტყობინების წესი არ დაარქვა "შეცილების ვადას". "შეცილება" გამოიყენე მოტყუების/შეცილების რეჟიმისთვის; ნაკლზე გამოიყენე "პრეტენზია", "შეტყობინება", "უფლების დაკარგვა/პრეიუდიცია" ან "მოთხოვნის ხანდაზმულობა".';
        }

        if (!empty($questionOutcomes)) {
            $lines[] = '';
            $lines[] = 'მომხმარებლის კითხვაში მოთხოვნილი/ხსენებული შედეგები: '
                . implode(', ', array_map(fn (string $key) => self::OUTCOME_KEYWORDS[$key]['label'], $questionOutcomes));
        }

        if (!empty($sourceRows)) {
            $lines[] = '';
            $lines[] = 'მოძიებული ნორმებიდან ამოცნობილი შედეგები:';
            foreach ($sourceRows as $row) {
                $lines[] = "• {$row}";
            }
        }

        if ($hasWeakCases) {
            $lines[] = '';
            $lines[] = 'სასამართლო პრაქტიკა: weak/supporting საქმეები არ გამოიყენო პირდაპირ პრეცედენტად; გამოიყენე მხოლოდ შეზღუდულ ანალოგიად და არ განაზოგადო.';
        }

        $lines[] = '';
        $lines[] = 'CHECK BEFORE FINAL ANSWER: თუ მომხმარებელი ითხოვს ბათილობას, მაგრამ წყარო მხოლოდ მოშლას/ფასის შემცირებას/ზიანს იძლევა, თქვი რომ ეს არის სხვა remedy და არა ბათილობა.';
        $lines[] = '────────────────────────';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @return array<int, string>
     */
    private function sourceOutcomeRows(array $matsneResults): array
    {
        $rows = [];
        $seen = [];

        foreach ($matsneResults as $doc) {
            $outcomes = $this->detectOutcomes(($doc['title'] ?? '') . "\n" . ($doc['excerpt'] ?? ''));
            if (empty($outcomes)) {
                continue;
            }

            $article = $doc['_article_num'] ?? $doc['article_num'] ?? null;
            $title = trim((string) ($doc['title'] ?? 'ნორმა'));
            $label = $article ? "{$title}, მუხლი {$article}" : $title;
            $outcomeText = implode(', ', array_map(fn (string $key) => self::OUTCOME_KEYWORDS[$key]['label'], $outcomes));
            $row = "{$label} → {$outcomeText}";

            if (!isset($seen[$row])) {
                $rows[] = $row;
                $seen[$row] = true;
            }
        }

        return array_slice($rows, 0, 12);
    }

    /**
     * @return array<int, string>
     */
    private function detectOutcomes(string $text): array
    {
        $lower = mb_strtolower($text);
        $found = [];

        foreach (self::OUTCOME_KEYWORDS as $key => $config) {
            foreach ($config['needles'] as $needle) {
                if (str_contains($lower, $needle)) {
                    $found[] = $key;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     */
    private function hasDefectRemedySources(array $matsneResults): bool
    {
        foreach ($matsneResults as $doc) {
            $text = mb_strtolower(($doc['title'] ?? '') . "\n" . ($doc['excerpt'] ?? ''));
            if (str_contains($text, 'ნაკლ')
                && $this->containsAny($text, ['მოშლ', 'ფასის შემცირ', 'გამოსწორ', 'შეცვალ', 'ზიან', 'პრეტენზ'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     */
    private function hasDefectNoticeSources(array $matsneResults): bool
    {
        foreach ($matsneResults as $doc) {
            $text = mb_strtolower(($doc['title'] ?? '') . "\n" . ($doc['excerpt'] ?? ''));
            if (str_contains($text, 'ნაკლ')
                && $this->containsAny($text, ['პრეტენზ', 'აცნობ', 'ეცნობ', 'ერთმევა', 'უფლების დაკარგ'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     */
    private function hasWeakCourtSources(array $decisions): bool
    {
        foreach ($decisions as $decision) {
            if (($decision['answer_role'] ?? null) !== 'primary') {
                return true;
            }
            if (in_array('weak_context_match', $decision['quality_flags'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $haystack
     */
    private function containsAny(string $text, array $haystack): bool
    {
        foreach ($haystack as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
