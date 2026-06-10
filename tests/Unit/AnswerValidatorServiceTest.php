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
}
