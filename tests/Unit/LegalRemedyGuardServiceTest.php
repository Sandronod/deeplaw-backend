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

    public function test_it_builds_procedural_boundary_rule_frame_for_magistrate_claim_value(): void
    {
        $service = new LegalRemedyGuardService();

        $block = $service->buildPromptBlock(
            question: 'ძირითადი სარჩელით მოთხოვნილია უკანონო მფლობელობიდან ნივთის გამოთხოვა. მოსამზადებელ სხდომაზე მოპასუხემ შემოიტანა შეგებებული სარჩელი და ითხოვს მესაკუთრედ ცნობას, თუმცა სარჩელის ფასია 50 000 ლარი. ეს ორივე სარჩელი მაგისტრატი მოსამართლის განსჯადია?',
        );

        $this->assertStringContainsString('LEGAL CONSEQUENCE RULE ATOMS', $block);
        $this->assertStringContainsString('civil_procedure.magistrate_claim_value', $block);
        $this->assertStringContainsString('claim_value <= 50000', $block);
        $this->assertStringContainsString('equal_included', $block);
        $this->assertStringContainsString('შეგებებული სარჩელის ფასი = 50 000 GEL', $block);
        $this->assertStringContainsString('50 000 <= 50 000', $block);
        $this->assertStringContainsString('subject_matter_jurisdiction.magistrate', $block);
        $this->assertStringContainsString('ზუსტად 50 000 GEL შედის', $block);
        $this->assertStringContainsString('counterclaim_subject_matter_guard', $block);
    }
}
