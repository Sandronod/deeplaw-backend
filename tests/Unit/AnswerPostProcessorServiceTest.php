<?php

namespace Tests\Unit;

use App\Services\AI\AnswerPostProcessorService;
use PHPUnit\Framework\TestCase;

class AnswerPostProcessorServiceTest extends TestCase
{
    public function test_it_normalizes_defect_challenge_heading_without_llm_call(): void
    {
        $result = (new AnswerPostProcessorService())->process(
            '**[3] შეცილების ვადა დაფარული ნაკლის გამო მოთხოვნის შემთხვევაში**',
        );

        $this->assertStringContainsString('პრეტენზიის/შეტყობინების ვადა დაფარული ნაკლის გამო', $result['text']);
        $this->assertContains('normalized_defect_notice_terminology', $result['changes']);
    }

    public function test_it_injects_grounded_article_55_for_price_disproportion(): void
    {
        $answer = "- **Rule:** საქართველოს სამოქალაქო კოდექსი, მუხლი 54 — ბათილია ზნეობის საწინააღმდეგო გარიგება.\n"
            . '- **Apply:** მხოლოდ ფასის დისპროპორცია საკმარისი არ არის.';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'matsneResults' => [
                [
                    '_article_num' => 55,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'შესრულებასა და ანაზღაურებას შორის აშკარა შეუსაბამობა.',
                ],
            ],
        ]);

        $this->assertStringContainsString('სკ-ის 55-ე მუხლიც რელევანტურია', $result['text']);
        $this->assertContains('injected_grounded_article_55', $result['changes']);
    }

    public function test_it_softens_court_practice_claims_without_primary_authority(): void
    {
        $answer = 'სასამართლო პრაქტიკა არ აღიარებს მხოლოდ ფასის დისპროპორციას საკმარის საფუძვლად სკ-ის 54-ე მუხლისთვის.';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'finalDecisions' => [],
            'constCourtResults' => [
                ['case_number' => 'N3/7/679'],
            ],
        ]);

        $this->assertStringContainsString('პირდაპირი სასამართლო პრაქტიკა ვერ მოიძებნა', $result['text']);
        $this->assertContains('softened_non_primary_court_practice_claim', $result['changes']);
    }
}
