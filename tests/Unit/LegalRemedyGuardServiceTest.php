<?php

namespace Tests\Unit;

use App\Services\AI\LegalRemedyGuardService;
use PHPUnit\Framework\TestCase;

class LegalRemedyGuardServiceTest extends TestCase
{
    public function test_it_builds_source_grounded_remedy_frame(): void
    {
        $service = new LegalRemedyGuardService();

        $block = $service->buildPromptBlock(
            question: 'მყიდველი ითხოვს ხელშეკრულების ბათილობას ნაკლის გამო.',
            matsneResults: [
                [
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    '_article_num' => 491,
                    'excerpt' => 'მყიდველს შეუძლია ნივთის ნაკლის გამო მოითხოვოს ხელშეკრულების მოშლა.',
                ],
                [
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    '_article_num' => 492,
                    'excerpt' => 'მას შეუძლია მოითხოვოს ფასის შემცირება იმ ოდენობით, რაც საჭიროა ნაკლის გამოსასწორებლად.',
                ],
                [
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    '_article_num' => 495,
                    'excerpt' => 'მყიდველი ვალდებულია ნაკლის აღმოჩენიდან შესაბამის ვადაში წარუდგინოს გამყიდველს პრეტენზია; წინააღმდეგ შემთხვევაში უფლება ერთმევა.',
                ],
            ],
            decisions: [
                [
                    'case_num' => '3კ/722',
                    'answer_role' => 'supporting',
                ],
            ],
        );

        $this->assertStringContainsString('LEGAL OUTCOME / REMEDY GUARD', $block);
        $this->assertStringContainsString('მოშლა ≠ ბათილობა', $block);
        $this->assertStringContainsString('მუხლი 491', $block);
        $this->assertStringContainsString('ხელშეკრულების მოშლა', $block);
        $this->assertStringContainsString('ფასის შემცირება', $block);
        $this->assertStringContainsString('DEFECT REMEDY RULE', $block);
        $this->assertStringContainsString('BAD:', $block);
        $this->assertStringContainsString('GOOD:', $block);
        $this->assertStringContainsString('NOTICE / PRECLUSION RULE', $block);
        $this->assertStringContainsString('weak/supporting საქმეები', $block);
    }
}
