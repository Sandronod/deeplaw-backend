<?php

namespace App\Services\Legal;

/**
 * Rule-based domain classifier for Georgian laws and legal questions.
 *
 * No API call — fast keyword matching.
 * Maps a law title or user question → one of the 10 legal domains.
 *
 * Domains: civil | criminal | admin | corporate | labor | property | tax | family | procedure | echr
 *
 * Also derives hierarchy_level from law category/title:
 *   1 = constitution
 *   2 = organic law
 *   3 = law (კანონი, კოდექსი)
 *   4 = presidential decree
 *   5 = government decree / resolution
 *   6 = ministerial order
 *   7 = local act
 *
 * And specificity_level:
 *   1 = specific (lex specialis — named area, narrow scope)
 *   2 = general  (lex generalis — civil code, criminal code, etc.)
 */
class LegalDomainClassifier
{
    private const DOMAIN_SIGNALS = [
        'civil' => [
            'სამოქალაქო', 'ვალდებულება', 'ხელშეკრულება', 'ნასყიდობა', 'ქირავნობა',
            'საჩუქარი', 'კომპენსაცია', 'ზიანი', 'სამოქალაქო კოდექსი',
            'ფიზიკური პირი', 'კერძო სამართალი', 'delikti', 'delict',
            'civil', 'tort', 'contract', 'liability',
            'ქირავ', 'კონტრაქტ', 'გამქირავ', 'მქირავ',
        ],
        'criminal' => [
            'სისხლის სამართალი', 'სისხლი', 'დანაშაული', 'სასჯელი', 'პატიმრობა',
            'თავისუფლება', 'ბრალდება', 'განაჩენი', 'მსჯავრდებული', 'სასჯელდამდები',
            'ქურდობა', 'თაღლიობა', 'მკვლელობა', 'ძალადობა', 'ნარკოტიკ',
            'criminal', 'penal', 'offense', 'conviction',
        ],
        'admin' => [
            'ადმინისტრაციული', 'საჯარო სამართალი', 'სამინისტრო', 'სააგენტო',
            'ნებართვა', 'ლიცენზია', 'რეგისტრაცია', 'ორგანო', 'საჯარო',
            'ადმინისტრაციული საჩივარი', 'ადმინისტრაციული წარმოება',
            'administrative', 'permit', 'license', 'public authority',
        ],
        'corporate' => [
            'სამეწარმეო', 'კომპანია', 'შეზღუდული პასუხისმგებლობის', 'შპს', 'სს',
            'სააქციო', 'დირექტორი', 'პარტნიორი', 'წილი', 'კაპიტალი', 'ბალანსი',
            'კორპორატიული', 'გაკოტრება', 'გადახდისუუნარობა', 'კრედიტორთა რეესტრი',
            'რეაბილიტაცია', 'კრედიტორთა კოლექტიური დაკმაყოფილება',
            'corporate', 'company', 'shareholder', 'director', 'bankruptcy', 'insolvency',
        ],
        'labor' => [
            'შრომა', 'შრომის კოდექსი', 'დასაქმება', 'სამუშაო', 'ხელფასი',
            'შვებულება', 'გათავისუფლება', 'სამუშაო ადგილი', 'პროფკავშირი',
            'დამსაქმებელ', 'დასაქმებულ', 'გაათავისუფლ', 'გამათავისუფლ',
            'labor', 'employment', 'wage', 'dismissal', 'workplace',
        ],
        'property' => [
            'საკუთრება', 'უძრავი ქონება', 'მიწა', 'ნაკვეთი', 'გირავნობა',
            'იპოთეკა', 'სერვიტუტი', 'მეიჯარე', 'მოიჯარე', 'ქონებრივი',
            'property', 'real estate', 'mortgage', 'land', 'ownership',
        ],
        'tax' => [
            'საგადასახადო', 'გადასახადი', 'დღგ', 'საშემოსავლო', 'მოგების გადასახადი',
            'საბაჟო', 'საგადასახადო კოდექსი', 'შემოსავლის სამსახური',
            'tax', 'vat', 'customs', 'revenue', 'fiscal',
        ],
        'family' => [
            'ოჯახი', 'ქორწინება', 'განქორწინება', 'შვილი', 'მეუღლე',
            'მშობელი', 'მეურვეობა', 'შვილობა', 'ალიმენტი', 'მემკვიდრეობა',
            'family', 'marriage', 'divorce', 'child', 'custody', 'inheritance',
        ],
        'procedure' => [
            'სამოქალაქო საპროცესო', 'საპროცესო', 'სარჩელი', 'სასამართლო განხილვა',
            'სამართლებრივი დახმარება', 'მტკიცებულება', 'გასაჩივრება', 'შეჩერება',
            'საკასაციო', 'საჩივარი', 'დასაშვებლ', 'დაუშვებლ', 'სახელმწიფო ბაჟ',
            'civil procedure', 'procedural', 'appeal', 'evidence', 'litigation',
        ],
        'echr' => [
            'echr', 'სტრასბურგი', 'ევროპული სასამართლო', 'ადამიანის უფლებათა',
            'კონვენცია', 'პროტოკოლი', 'კომიტეტი', 'ყოვლისმომცველი',
            'european court', 'human rights convention', 'strasbourg',
        ],
    ];

    // Category → hierarchy_level mapping
    private const HIERARCHY_MAP = [
        'კონსტიტუცია'        => 1,
        'ორგანული კანონი'    => 2,
        'კოდექსი'            => 3,
        'კანონი'             => 3,
        'საპრეზიდენტო'       => 4,
        'პრეზიდენტის'        => 4,
        'მთავრობის'          => 5,
        'დადგენილება'        => 5,
        'განკარგულება'       => 5,
        'მინისტრის'          => 6,
        'მინისტრი'           => 6,
        'ბრძანება'           => 6,
        'ბრძანებულება'       => 6,
        'ადგილობრივი'        => 7,
        'საკრებულო'          => 7,
    ];

    // Titles that are general laws (lex generalis → specificity_level = 2)
    private const GENERAL_TITLES = [
        'სამოქალაქო კოდექსი',
        'სისხლის სამართლის კოდექსი',
        'ადმინისტრაციული საპროცესო კოდექსი',
        'ზოგადი ადმინისტრაციული კოდექსი',
        'სამოქალაქო საპროცესო კოდექსი',
        'საგადასახადო კოდექსი',
        'შრომის კოდექსი',
        'სისხლის სამართლის საპროცესო კოდექსი',
    ];

    /**
     * Classify a text (law title or user question) into a domain.
     * Returns the best-matching domain string, or null if unclear.
     */
    public function classifyText(string $text): ?string
    {
        $lower  = mb_strtolower(trim($text));
        $scores = [];

        foreach (self::DOMAIN_SIGNALS as $domain => $signals) {
            $score = 0;
            foreach ($signals as $sig) {
                if (str_contains($lower, mb_strtolower($sig))) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$domain] = $score;
            }
        }

        if (empty($scores)) {
            return null;
        }

        arsort($scores);
        return array_key_first($scores);
    }

    /**
     * Classify multiple domains at once (for multi-issue queries).
     * Returns array of unique domain strings.
     *
     * @param  string[] $texts
     * @return string[]
     */
    public function classifyMultiple(array $texts): array
    {
        $domains = [];
        foreach ($texts as $text) {
            $d = $this->classifyText($text);
            if ($d && !in_array($d, $domains, true)) {
                $domains[] = $d;
            }
        }
        return $domains;
    }

    /**
     * Derive hierarchy_level from law category + title.
     */
    public function hierarchyLevel(string $category, string $title = ''): int
    {
        $combined = mb_strtolower($category . ' ' . $title);

        foreach (self::HIERARCHY_MAP as $keyword => $level) {
            if (str_contains($combined, mb_strtolower($keyword))) {
                return $level;
            }
        }

        return 3; // default: ordinary law
    }

    /**
     * Derive specificity_level from law title.
     * General/codified laws = 2, specific laws = 1.
     */
    public function specificityLevel(string $title): int
    {
        $lower = mb_strtolower(trim($title));
        foreach (self::GENERAL_TITLES as $general) {
            if (str_contains($lower, mb_strtolower($general))) {
                return 2;
            }
        }
        return 1;
    }

    /**
     * Extract effective_year from an adopted_at date string or Carbon instance.
     */
    public function effectiveYear(mixed $adoptedAt): ?int
    {
        if (!$adoptedAt) {
            return null;
        }
        if ($adoptedAt instanceof \Carbon\Carbon) {
            return $adoptedAt->year;
        }
        $year = (int) substr((string) $adoptedAt, 0, 4);
        return ($year >= 1991 && $year <= (int) date('Y')) ? $year : null;
    }
}
