<?php

namespace App\Services\AI;

class LegalAuthorityTaxonomyService
{
    public static function forDomesticDecision(array $decision, string $answerRole = 'supporting'): array
    {
        if ($answerRole !== 'primary') {
            return self::status(
                'supporting_analogy',
                'დამხმარე ანალოგია',
                false,
                'არ გამოიყენო როგორც პირდაპირი ან სავალდებულო სასამართლო პრაქტიკა.',
            );
        }

        $court = (string) ($decision['court'] ?? '');
        $chamber = (string) ($decision['chamber'] ?? '');
        $isSupreme = str_contains($court, 'უზენაეს');
        $isFullOrJoint = str_contains($chamber, 'გაერთ') || str_contains($chamber, 'სრული');

        if ($isSupreme && $isFullOrJoint) {
            return self::status(
                'binding_full_chamber',
                'უზენაესი სასამართლოს სრული/გაერთიანებული შემადგენლობის მაღალი ავტორიტეტი',
                true,
                'შეიძლება მიეთითოს როგორც ძლიერი/სავალდებულო განმარტება მხოლოდ ამ სტატუსის ფარგლებში.',
            );
        }

        if ($isSupreme) {
            return self::status(
                'persuasive_supreme',
                'უზენაესი სასამართლოს persuasive პრაქტიკა',
                false,
                'გამოიყენე როგორც მაღალი ავტორიტეტის მქონე პრაქტიკა, მაგრამ არ უწოდო სავალდებულო პრეცედენტი.',
            );
        }

        if (str_contains($court, 'სააპელაციო')) {
            return self::status(
                'persuasive_appellate',
                'სააპელაციო სასამართლოს persuasive პრაქტიკა',
                false,
                'გამოიყენე როგორც დამხმარე პრაქტიკა; არ წარმოადგინო სავალდებულო პრეცედენტად.',
            );
        }

        return self::status(
            'persuasive_lower_court',
            'ქვედა ინსტანციის persuasive პრაქტიკა',
            false,
            'გამოიყენე ფრთხილად, მხოლოდ დამხმარე პრაქტიკად.',
        );
    }

    public static function comparativeNonBinding(string $sourceLabel): array
    {
        return self::status(
            'comparative_non_binding',
            "{$sourceLabel} შედარებითი, არასავალდებულო წყარო",
            false,
            'ქართულ სამართალში არ არის სავალდებულო; გამოიყენე მხოლოდ შედარებით არგუმენტად.',
        );
    }

    public static function constitutionalCourt(): array
    {
        return self::status(
            'constitutional_binding_erga_omnes',
            'საკონსტიტუციო სასამართლოს erga omnes ეფექტის მქონე წყარო',
            true,
            'თუ გადაწყვეტილებით ნორმა არაკონსტიტუციურად არის ცნობილი, სამართლებრივი შედეგი სავალდებულოა ყველა სასამართლოსთვის.',
        );
    }

    public static function echr(): array
    {
        return self::status(
            'echr_interpretive_authority',
            'ECHR კონვენციური სტანდარტის განმარტება',
            true,
            'გამოიყენე როგორც კონვენციის სტანდარტის განმარტება; არ აურიო საქართველოს შიდა სასამართლო პრაქტიკასთან.',
        );
    }

    public static function legislation(): array
    {
        return self::status(
            'binding_legislation',
            'სავალდებულო კანონმდებლობა',
            true,
            'გამოიყენე როგორც სამართლებრივი ნორმის პირველადი წყარო, მოქმედი დროითი რედაქციის გათვალისწინებით.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function status(string $status, string $label, bool $binding, string $caveat): array
    {
        return [
            'authority_status' => $status,
            'authority_status_label' => $label,
            'authority_binding' => $binding,
            'authority_caveat' => $caveat,
        ];
    }
}
