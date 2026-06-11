<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Legal Consequence Rule Atoms
    |--------------------------------------------------------------------------
    |
    | Curated, deterministic legal-consequence primitives. These are not
    | question-specific patches; they are reusable rule atoms used to compute
    | boundary-sensitive outcomes before the LLM writes the explanation.
    |
    | Later this registry can be moved to DB/JSON with effective-date versioning.
    |
    */
    'rules' => [
        'civil_procedure.magistrate_claim_value' => [
            'article' => 'სსკ 9',
            'condition' => 'claim_value <= 50000',
            'fact_keys' => ['claim_value', 'main_claim_value', 'counterclaim_value'],
            'operator' => '<=',
            'threshold' => 50000,
            'outcome_true' => 'subject_matter_jurisdiction.magistrate',
            'outcome_false' => 'subject_matter_jurisdiction.district_or_city_court',
            'boundary_rule' => 'equal_included',
            'category' => 'procedural_outcome.subject_matter_jurisdiction',
            'trigger_all_keywords' => [
                ['მაგისტრატ', 'განსჯად', 'საგნობრივ', 'რაიონულ', 'საქალაქო', 'კომპეტენც'],
                ['სარჩელის ფას', 'ფასია', 'ღირებულ', 'ლარ', '₾'],
            ],
            'prompt_true' => 'ეს ოდენობა არ აღემატება {threshold} GEL-ს.',
            'prompt_false' => 'ეს ოდენობა აღემატება {threshold} GEL-ს.',
            'prompt_equal' => 'ზუსტად {threshold} GEL შედის მაგისტრატი მოსამართლის ზღვარში; არ თქვა, რომ ზუსტად {threshold} ზღვარს სცდება.',
            'summary_lines' => [
                'მაგისტრატი მოსამართლე: სარჩელის ფასი ≤ {threshold} ₾ ({article})',
                'სარჩელის ფასი > {threshold} ₾ → რაიონული სასამართლო',
            ],
            'validation_target_keywords' => ['მაგისტრატ', 'რაიონულ', 'საქალაქო', 'განსჯად', 'კომპეტენც'],
            'validation_wrong_keywords' => ['არ არის მაგისტრატ', 'არ შედის მაგისტრატ', 'არ ექვემდებარ', 'სცდება', 'გარეთ', 'რაიონულ', 'საქალაქო'],
            'validation_correct_keywords' => ['კომპეტენცია აქვს', 'განიხილავს', 'განიხილება', 'განსჯადია', 'განსჯადობის ფარგლებშია', 'შედის', 'არ სცდება', 'არ აჭარბ'],
            'validation_exclusion_keywords' => ['არ არის მაგისტრატ', 'არ შედის მაგისტრატ', 'არ ექვემდებარ', 'რაიონულ', 'საქალაქო'],
        ],
        'civil_procedure.counterclaim_subject_matter_guard' => [
            'article' => 'სსკ 188 / სსკ 21',
            'condition' => 'counterclaim must still fit subject-matter jurisdiction',
            'outcome_true' => 'counterclaim_admissibility.check_subject_matter_jurisdiction',
            'boundary_rule' => 'no_subject_matter_jurisdiction_by_agreement',
            'category' => 'procedural_outcome.counterclaim_admissibility',
            'trigger_any_keywords' => ['შეგებებულ', 'შემხვედრ'],
            'prompt_true' => 'შეგებებული სარჩელი იმავე საქმეში განიხილება მხოლოდ მაშინ, თუ პროცედურული წინაპირობები, მათ შორის საგნობრივი განსჯადობა, დაცულია.',
            'reason' => 'შეგებებული სარჩელი არ ქმნის საგნობრივ განსჯადობაზე შეთანხმებას.',
        ],
        'civil_procedure.counterclaim_preparatory_stage_guard' => [
            'article' => 'სსკ 188',
            'condition' => 'counterclaim is submitted before or during preparatory hearing',
            'outcome_true' => 'counterclaim_admissibility.check_preparatory_stage',
            'boundary_rule' => 'stage_sensitive',
            'category' => 'procedural_outcome.counterclaim_admissibility',
            'trigger_all_keywords' => [
                ['შეგებებულ', 'შემხვედრ'],
                ['მოსამზადებელ', 'მოსამზადებელი'],
            ],
            'prompt_true' => 'შეგებებული სარჩელის დასაშვებობაზე მსჯელობისას ცალკე შეამოწმე, შეტანილია თუ არა იგი მოსამზადებელი სხდომის ეტაპზე/ამ ეტაპამდე და არ აურიო ეს საკითხი საგნობრივ განსჯადობასთან.',
            'reason' => 'შეგებებული სარჩელის დრო/ეტაპი დამოუკიდებელი დასაშვებობის საკითხია.',
        ],
        'civil_procedure.appeal_deadline_guard' => [
            'article' => 'საპროცესო კოდექსის შესაბამისი გასაჩივრების ნორმა',
            'condition' => 'question asks about appeal/complaint deadline',
            'outcome_true' => 'procedural_deadline.appeal_or_complaint',
            'boundary_rule' => 'deadline_must_match_retrieved_norm',
            'category' => 'procedural_outcome.deadline',
            'trigger_any_keywords' => ['გასაჩივრ', 'საჩივრ', 'სააპელაციო', 'საკასაციო', 'კასაცი'],
            'prompt_true' => 'თუ პასუხში ვადას ასახელებ, ის უნდა ემთხვეოდეს მოძიებულ მოქმედ ნორმას; მხოლოდ კითხვა/ზოგადი ცოდნა ვადის დასადგენად საკმარისი არ არის.',
            'reason' => 'გასაჩივრების ვადა წყაროზე მიბმული procedural outcome-ია.',
        ],
        'civil_procedure.limitation_period_guard' => [
            'article' => 'შესაბამისი მატერიალური/საპროცესო ნორმა',
            'condition' => 'question asks about limitation period',
            'outcome_true' => 'deadline.limitation_period',
            'boundary_rule' => 'period_must_match_retrieved_norm',
            'category' => 'procedural_outcome.deadline',
            'trigger_any_keywords' => ['ხანდაზმ'],
            'prompt_true' => 'ხანდაზმულობის ვადაზე პასუხი მიუთითე მხოლოდ შესაბამისი ნორმის/წყაროს საფუძველზე და გამიჯნე იგი საპროცესო გასაჩივრების ვადისგან.',
            'reason' => 'ხანდაზმულობის ვადა სამართლებრივ შედეგს ცვლის და ცალკე ფაქტად უნდა გამოიყოს.',
        ],
    ],
];
