<?php

namespace Tests\Unit;

use App\Services\AI\AnswerValidatorService;
use PHPUnit\Framework\TestCase;

class AnswerValidatorServiceTest extends TestCase
{
    public function test_it_flags_article_not_present_in_retrieved_norms(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'შრომითი კოდექსის 999-ე მუხლის მიხედვით მოთხოვნა დასაშვებია.',
            matsneResults: [
                [
                    '_article_num' => 48,
                    'title' => 'საქართველოს შრომის კოდექსი',
                    'excerpt' => 'მუხლი 48. შრომითი ხელშეკრულების შეწყვეტის წესი.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('999', $result['checked']['answer_articles']);
        $this->assertContains('48', $result['checked']['source_articles']);
        $this->assertSame('unsupported_article', $result['flags'][0]['type']);
        $this->assertSame('999', $result['flags'][0]['value']);
    }

    public function test_it_passes_supported_articles_and_deadlines(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: '48-ე მუხლის მიხედვით დასაქმებულს 30 დღის ვადა აქვს, ხოლო დამსაქმებელს 7 დღეში უნდა ეცნობოს.',
            matsneResults: [
                [
                    '_article_num' => 48,
                    'title' => 'საქართველოს შრომის კოდექსი',
                    'excerpt' => 'მუხლი 48. დასაქმებულს უფლება აქვს მიმართოს სასამართლოს 30 კალენდარული დღის ვადაში. დამსაქმებელი ვალდებულია 7 კალენდარული დღის ვადაში აცნობოს საფუძველი.',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
        $this->assertSame(['7', '30'], $result['checked']['answer_legal_numbers']);
    }

    public function test_it_does_not_treat_article_parts_as_separate_articles(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: '102-ე მუხლის 1-ლი ნაწილი ადგენს მტკიცების ტვირთს.',
            matsneResults: [
                [
                    '_article_num' => 102,
                    'excerpt' => 'მუხლი 102. მტკიცების ტვირთი. 1. თითოეულმა მხარემ უნდა დაამტკიცოს გარემოებანი.',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame(['102'], $result['checked']['answer_articles']);
    }

    public function test_it_warns_when_answer_uses_deadline_not_found_in_sources(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: '48-ე მუხლის მიხედვით დასაქმებულს 45 დღის ვადა აქვს.',
            matsneResults: [
                [
                    '_article_num' => 48,
                    'title' => 'საქართველოს შრომის კოდექსი',
                    'excerpt' => 'მუხლი 48. დასაქმებულს უფლება აქვს მიმართოს სასამართლოს 30 კალენდარული დღის ვადაში.',
                ],
            ],
        );

        $this->assertSame('warn', $result['verdict']);
        $this->assertSame('unsupported_number', $result['flags'][0]['type']);
        $this->assertSame('45', $result['flags'][0]['value']);
    }

    public function test_it_flags_case_law_claim_when_no_case_source_exists(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'უზენაესი სასამართლოს პრაქტიკის მიხედვით, დამსაქმებელს მტკიცების ტვირთი ეკისრება.',
            matsneResults: [
                [
                    '_article_num' => 48,
                    'excerpt' => 'მუხლი 48. შრომითი ხელშეკრულების შეწყვეტის წესი.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertSame('unsupported_case_law_claim', $result['flags'][0]['type']);
    }

    public function test_it_allows_articles_grounded_in_court_decision_text(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'სსკ-ის 396-ე მუხლის მიხედვით საკასაციო ვადა დაცული უნდა იყოს.',
            decisions: [
                [
                    'case_num' => 'ას-1-2024',
                    'excerpt' => 'სასამართლომ იმსჯელა სამოქალაქო საპროცესო კოდექსის 396-ე მუხლის გამოყენებაზე.',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
        $this->assertContains('396', $result['checked']['source_articles']);
    }

    public function test_it_does_not_flag_negative_case_law_statement(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'ამ კითხვაზე სასამართლო პრაქტიკა მოძიებულ წყაროებში ვერ მოიძებნა.',
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
    }

    public function test_it_warns_when_weak_case_is_used_as_direct_authority(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: '3კ/722 საქმეში სასამართლომ დაადგინა, რომ ფასთა სხვაობა ამორალურ გარიგებას ადასტურებს.',
            decisions: [
                [
                    'case_num' => '3კ/722',
                    'answer_role' => 'supporting',
                    'quality_flags' => ['weak_context_match'],
                    'semantic_relevance_score' => 31.0,
                    'semantic_relevance' => ['confidence' => 'low'],
                ],
            ],
        );

        $this->assertSame('warn', $result['verdict']);
        $this->assertSame('weak_case_authority_claim', $result['flags'][0]['type']);
        $this->assertSame('3კ/722', $result['flags'][0]['value']);
    }

    public function test_it_allows_weak_case_when_labeled_as_analogy(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: '3კ/722 შეიძლება მხოლოდ დამხმარე ანალოგიად იყოს ნახსენები და არა პირდაპირ პრაქტიკად.',
            decisions: [
                [
                    'case_num' => '3კ/722',
                    'answer_role' => 'supporting',
                    'quality_flags' => ['weak_context_match'],
                    'semantic_relevance_score' => 31.0,
                    'semantic_relevance' => ['confidence' => 'low'],
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
    }

    public function test_it_flags_non_binding_case_called_binding_precedent(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: '3კ/722 არის სავალდებულო პრეცედენტი და სასამართლოები ვალდებულნი არიან იგივე წესით იმოქმედონ.',
            decisions: [
                [
                    'case_num' => '3კ/722',
                    'answer_role' => 'primary',
                    'authority_status' => 'persuasive_supreme',
                    'authority_binding' => false,
                    'quality_flags' => [],
                    'semantic_relevance_score' => 80.0,
                    'semantic_relevance' => ['confidence' => 'high'],
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertSame('non_binding_case_called_binding', $result['flags'][0]['type']);
    }

    public function test_it_allows_non_binding_case_when_caveat_is_explicit(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: '3კ/722 არ არის სავალდებულო პრეცედენტი, მაგრამ შეიძლება გამოყენებულ იქნეს როგორც persuasive პრაქტიკა.',
            decisions: [
                [
                    'case_num' => '3კ/722',
                    'answer_role' => 'primary',
                    'authority_status' => 'persuasive_supreme',
                    'authority_binding' => false,
                    'quality_flags' => [],
                    'semantic_relevance_score' => 80.0,
                    'semantic_relevance' => ['confidence' => 'high'],
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
    }

    public function test_it_flags_general_practice_confirmation_when_only_weak_cases_exist(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'პირდაპირი პრაქტიკა ვერ მოიძებნა, თუმცა სასამართლო პრაქტიკა ადასტურებს, რომ ფასთა სხვაობა ამორალურ გარიგებას ქმნის.',
            decisions: [
                [
                    'case_num' => '3კ/722',
                    'answer_role' => 'supporting',
                    'quality_flags' => ['weak_context_match'],
                    'semantic_relevance_score' => 31.0,
                    'semantic_relevance' => ['confidence' => 'low'],
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertSame('overstated_weak_case_law_claim', $result['flags'][0]['type']);
    }

    public function test_it_flags_defect_remedy_when_answer_turns_it_into_nullity(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'მყიდველს აქვს უფლება მოითხოვოს ხელშეკრულების ბათილობა ნაკლის საფუძველზე.',
            matsneResults: [
                [
                    '_article_num' => 491,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მყიდველს შეუძლია ნივთის ნაკლის გამო მოითხოვოს ხელშეკრულების მოშლა.',
                ],
                [
                    '_article_num' => 492,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მყიდველს შეუძლია მოითხოვოს ფასის შემცირება ნაკლის გამოსასწორებლად.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('defect_nullity_conflation', array_column($result['flags'], 'type'));
    }

    public function test_it_flags_defect_nullity_when_answer_uses_article_491_as_invalidity_basis(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'ბათილობის მოთხოვნა უფრო საფუძვლიანია მოტყუების (სკ-ის 81) და ნაკლის (სკ-ის 491) საფუძვლით.',
            matsneResults: [
                [
                    '_article_num' => 491,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მყიდველს შეუძლია ნივთის ნაკლის გამო მოითხოვოს ხელშეკრულების მოშლა.',
                ],
                [
                    '_article_num' => 492,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მყიდველს შეუძლია მოითხოვოს ფასის შემცირება ნაკლის გამოსასწორებლად.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('defect_nullity_conflation', array_column($result['flags'], 'type'));
    }

    public function test_it_allows_answer_that_distinguishes_defect_remedies_from_nullity(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'ნაკლი თავისთავად არ არის ბათილობის საფუძველი; მყიდველს შეუძლია მოითხოვოს ხელშეკრულების მოშლა ან ფასის შემცირება.',
            matsneResults: [
                [
                    '_article_num' => 491,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მყიდველს შეუძლია ნივთის ნაკლის გამო მოითხოვოს ხელშეკრულების მოშლა.',
                ],
                [
                    '_article_num' => 492,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მყიდველს შეუძლია მოითხოვოს ფასის შემცირება ნაკლის გამოსასწორებლად.',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
    }

    public function test_it_warns_when_defect_notice_is_called_challenge_period(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'შეცილების ვადა დაფარული ნაკლის გამო მოთხოვნის შემთხვევაში განისაზღვრება სკ-ის 495-ე მუხლით.',
            matsneResults: [
                [
                    '_article_num' => 495,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'თუ მყიდველი მეწარმეა, ის ვალდებულია ნივთის ნაკლის აღმოჩენიდან შესაბამის ვადაში წარუდგინოს გამყიდველს პრეტენზია; წინააღმდეგ შემთხვევაში მას უფლება ერთმევა.',
                ],
            ],
        );

        $this->assertSame('warn', $result['verdict']);
        $this->assertContains('defect_notice_as_challenge_period', array_column($result['flags'], 'type'));
    }

    public function test_it_allows_defect_notice_when_distinguished_from_challenge(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'სკ-ის 495-ე მუხლი ნაკლის გამო ადგენს პრეტენზიის/შეტყობინების წესს და არა შეცილების ვადას.',
            matsneResults: [
                [
                    '_article_num' => 495,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'თუ მყიდველი მეწარმეა, ის ვალდებულია ნივთის ნაკლის აღმოჩენიდან შესაბამის ვადაში წარუდგინოს გამყიდველს პრეტენზია; წინააღმდეგ შემთხვევაში მას უფლება ერთმევა.',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
    }

    public function test_it_flags_contradictory_magistrate_threshold_boundary_application(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'ორივე სარჩელი არ არის მაგისტრატი მოსამართლის საგნობრივი განსჯადობის ფარგლებში, თუ სარჩელის ფასი ზუსტად 50 000 ლარია ან აღემატება მას. მაგისტრატი მოსამართლე განიხილავს მხოლოდ იმ საქმეებს, სადაც სარჩელის ფასი არ აღემატება 50 000 ლარს. თუ სარჩელის ფასი ზუსტად 50 000 ლარია, საქმე განიხილება მაგისტრატი მოსამართლის მიერ.',
            matsneResults: [
                [
                    '_article_num' => 9,
                    'title' => 'საქართველოს სამოქალაქო საპროცესო კოდექსი',
                    'excerpt' => 'მაგისტრატი მოსამართლე განიხილავს ქონებრივ დავებს, თუ სარჩელის ფასი არ აღემატება 50 000 ლარს.',
                ],
            ],
        );

        $flagTypes = array_column($result['flags'], 'type');

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('wrong_threshold_boundary', $flagTypes);
        $this->assertContains('contradictory_boundary_application', $flagTypes);
        $this->assertContains('50000', $result['checked']['answer_legal_numbers']);
    }

    public function test_it_allows_correct_magistrate_threshold_boundary_application(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'თუ სარჩელის ფასი ზუსტად 50 000 ლარია, ეს არ აღემატება ზღვარს და საქმე მაგისტრატი მოსამართლის განსჯადია. 50 000 ლარზე მეტი მოთხოვნა კი რაიონული სასამართლოს განსჯადობაზე მიუთითებს.',
            matsneResults: [
                [
                    '_article_num' => 9,
                    'title' => 'საქართველოს სამოქალაქო საპროცესო კოდექსი',
                    'excerpt' => 'მაგისტრატი მოსამართლე განიხილავს ქონებრივ დავებს, თუ სარჩელის ფასი არ აღემატება 50 000 ლარს.',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
        $this->assertSame(['50000'], $result['checked']['answer_legal_numbers']);
    }

    public function test_it_flags_generic_source_placeholder_in_large_issue_answer(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: "📕 კანონი/ნორმა: ადმინისტრაციული სამართალწარმოების ზოგადი პრინციპები\nგადაწყვეტილება მოწმდება სრულყოფილი სასამართლო კონტროლით.",
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('generic_source_placeholder', array_column($result['flags'], 'type'));
        $this->assertSame(1, $result['summary']['generic_source_placeholders']);
    }

    public function test_it_flags_source_line_with_code_name_but_no_article(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: "- **წყარო:** შრომის კოდექსი\n- თუ ბონუსი გამომუშავებულია, მისი არგადახდა დაუშვებელია.",
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('generic_source_placeholder', array_column($result['flags'], 'type'));
    }

    public function test_it_flags_personal_data_articles_not_found_when_personal_data_articles_are_retrieved(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: '- **წყარო:** პერსონალურ მონაცემთა დაცვის შესახებ სპეციალური კანონი (მუხლები მოძიებული არ არის, მაგრამ მისი გამოყენება სავალდებულოა)',
            matsneResults: [
                [
                    '_article_num' => 17,
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    'excerpt' => 'მუხლი 17. მონაცემთა უსაფრთხოება.',
                ],
            ],
        );

        $flagTypes = array_column($result['flags'], 'type');

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('generic_source_placeholder', $flagTypes);
        $this->assertContains('privacy_law_source_denied', $flagTypes);
    }

    public function test_it_flags_real_estate_registry_answer_that_omits_retrieved_registry_articles(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'თუ ბინა საჯარო რეესტრში არ არის რეგისტრირებული, მყიდველი მესაკუთრედ არ ითვლება.',
            matsneResults: [
                [
                    '_article_num' => 183,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მუხლი 183. უძრავი ნივთის შესაძენად აუცილებელია რეგისტრაცია საჯარო რეესტრში.',
                ],
                [
                    '_article_num' => 312,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მუხლი 312. რეესტრის მონაცემთა უტყუარობისა და სისრულის პრეზუმფცია.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('real_estate_registry_source_omission', array_column($result['flags'], 'type'));
        $this->assertSame(1, $result['summary']['special_source_omissions']);
    }

    public function test_it_flags_mortgage_answer_that_omits_retrieved_mortgage_articles(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'ბანკის იპოთეკა პრიორიტეტულია და ქონების რეალიზაცია შეუძლია.',
            matsneResults: [
                [
                    '_article_num' => 286,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მუხლი 286. იპოთეკა.',
                ],
                [
                    '_article_num' => 300,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მუხლი 300. იპოთეკით დატვირთული ნივთის რეალიზაციის მოთხოვნა.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('mortgage_source_omission', array_column($result['flags'], 'type'));
    }

    public function test_it_flags_real_estate_development_special_source_omissions(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'გაკოტრების პროცესში მყიდველები კრედიტორები არიან. დაურეგისტრირებელი ბინა მემკვიდრეობაში არ შედის. თაღლითობის განაჩენი გავლენას ახდენს სამოქალაქო დავაზე. კოლექტიური სარჩელი რთულია.',
            matsneResults: [
                [
                    '_article_num' => 5,
                    'title' => 'რეაბილიტაციისა და კრედიტორთა კოლექტიური დაკმაყოფილების შესახებ',
                    'excerpt' => 'მუხლი 5. კრედიტორული მოთხოვნები.',
                ],
                [
                    '_article_num' => 52,
                    'title' => 'რეაბილიტაციისა და კრედიტორთა კოლექტიური დაკმაყოფილების შესახებ',
                    'excerpt' => 'მუხლი 52. კრედიტორთა რეესტრის შედგენა.',
                ],
                [
                    '_article_num' => 1328,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მუხლი 1328. სამკვიდრო ქონება.',
                ],
                [
                    '_article_num' => 106,
                    'title' => 'საქართველოს სამოქალაქო საპროცესო კოდექსი',
                    'excerpt' => 'მუხლი 106. ფაქტები, რომლებიც არ საჭიროებენ მტკიცებას.',
                ],
                [
                    '_article_num' => 86,
                    'title' => 'საქართველოს სამოქალაქო საპროცესო კოდექსი',
                    'excerpt' => 'მუხლი 86. თანამონაწილეობის საფუძვლები.',
                ],
            ],
        );

        $flagTypes = array_column($result['flags'], 'type');

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('insolvency_source_omission', $flagTypes);
        $this->assertContains('inheritance_source_omission', $flagTypes);
        $this->assertContains('criminal_preclusion_source_omission', $flagTypes);
        $this->assertContains('joinder_source_omission', $flagTypes);
    }

    public function test_it_flags_civil_code_55_used_for_penalty_reduction(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: "📕 კანონი/ნორმა: საქართველოს სამოქალაქო კოდექსი, მუხლი 55\nგამოყენება: სასამართლოს შეუძლია 1 მლნ ლარის პირგასამტეხლო შეამციროს, თუ ის გადაჭარბებულია.",
            matsneResults: [
                [
                    '_article_num' => 55,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მუხლი 55. გარიგების ბათილობა ძალაუფლების ბოროტად გამოყენების გამო.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('civil_code_55_misuse', array_column($result['flags'], 'type'));
        $this->assertSame(1, $result['summary']['civil_code_55_misuse']);
    }

    public function test_it_flags_civil_code_55_used_for_reputational_damage(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: "📕 კანონი/ნორმა: საქართველოს სამოქალაქო კოდექსი, მუხლი 55\nმორალური/რეპუტაციული ზიანის ანაზღაურება დასაშვებია, თუ დადასტურდება არამატერიალური ზიანი.",
            matsneResults: [
                [
                    '_article_num' => 55,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მუხლი 55. გარიგების ბათილობა ძალაუფლების ბოროტად გამოყენების გამო.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('civil_code_55_misuse', array_column($result['flags'], 'type'));
    }

    public function test_it_flags_personal_data_issue_without_special_privacy_law(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'მონაცემთა გაჟონვაზე პასუხისმგებლობა ფასდება შრომის კოდექსის 60-ე და სამოქალაქო კოდექსის 417-ე მუხლებით.',
            matsneResults: [
                [
                    '_article_num' => 17,
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    'excerpt' => 'მუხლი 17. მონაცემთა უსაფრთხოება. მონაცემთა დამმუშავებელი ვალდებულია მიიღოს შესაბამისი ორგანიზაციული და ტექნიკური ზომები.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('privacy_law_omission', array_column($result['flags'], 'type'));
        $this->assertSame(1, $result['summary']['privacy_law_omissions']);
    }

    public function test_it_flags_when_answer_denies_retrieved_personal_data_law(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'სპეციალური ნორმა პერსონალურ მონაცემთა დაცვის შესახებ კანონის მიხედვით არ მოიძებნა.',
            matsneResults: [
                [
                    '_article_num' => 17,
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    'excerpt' => 'მუხლი 17. მონაცემთა უსაფრთხოება.',
                ],
            ],
        );

        $this->assertSame('fail', $result['verdict']);
        $this->assertContains('privacy_law_source_denied', array_column($result['flags'], 'type'));
        $this->assertSame(1, $result['summary']['privacy_law_omissions']);
    }

    public function test_it_allows_civil_code_55_for_immoral_transaction_context(): void
    {
        $validator = new AnswerValidatorService();

        $result = $validator->validate(
            answerText: 'სკ-ის 55-ე მუხლი რელევანტურია ამორალური გარიგებისა და შესრულებათა აშკარა შეუსაბამობის შესაფასებლად.',
            matsneResults: [
                [
                    '_article_num' => 55,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'მუხლი 55. გარიგების ბათილობა ძალაუფლების ბოროტად გამოყენების გამო.',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['flags']);
    }
}
