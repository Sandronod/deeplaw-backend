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
}
