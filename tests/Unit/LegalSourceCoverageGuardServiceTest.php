<?php

namespace Tests\Unit;

use App\Services\AI\LegalSourceCoverageGuardService;
use PHPUnit\Framework\TestCase;

class LegalSourceCoverageGuardServiceTest extends TestCase
{
    public function test_it_builds_special_source_guidance_for_large_fintech_casus(): void
    {
        $service = new LegalSourceCoverageGuardService();

        $block = $service->buildPromptBlock(
            'თანამშრომელი გაათავისუფლეს მონაცემთა გაჟონვის გამო. საქმე ეხება არაკონკურენციის შეთანხმებას, აქციების ოფციონს, 1 მლნ პირგასამტეხლოს, 2 მლნ ზიანს და პერსონალურ მონაცემთა დაცვის სამსახურის ჯარიმას.',
        );

        $this->assertStringContainsString('LARGE-CASUS SOURCE COVERAGE GUARD', $block);
        $this->assertStringContainsString('არაკონკურენციის შეზღუდვა', $block);
        $this->assertStringContainsString('პერსონალური მონაცემები', $block);
        $this->assertStringContainsString('პირგასამტეხლო', $block);
        $this->assertStringContainsString('მუხლები მოძიებული არ არის', $block);
        $this->assertStringContainsString('ადმინისტრაციული საპროცესო კოდექსი', $block);
        $this->assertStringContainsString('სამოქალაქო კოდექსის 55-ე მუხლი', $block);
        $this->assertStringContainsString('არ გამოიყენო პირგასამტეხლოს', $block);
    }

    public function test_it_stays_empty_for_unrelated_short_question(): void
    {
        $service = new LegalSourceCoverageGuardService();

        $this->assertSame('', $service->buildPromptBlock('რა არის სარჩელი?'));
    }

    public function test_it_builds_source_guidance_for_real_estate_development_casus(): void
    {
        $service = new LegalSourceCoverageGuardService();

        $block = $service->buildPromptBlock(
            'მყიდველებს ბინის ფასი გადახდილი აქვთ, მაგრამ საკუთრება საჯარო რეესტრში არ არის რეგისტრირებული. ბანკს აქვს იპოთეკა და იწყებს ქონების რეალიზაციას. კომპანია გაკოტრების ზღვარზეა, მყიდველი გარდაიცვალა, მეუღლე აცხადებს თანასაკუთრებას, აღძრულია თაღლითობის საქმე და 240 მყიდველს სურს კოლექტიური სარჩელი.',
        );

        $this->assertStringContainsString('უძრავი ქონება / რეგისტრაცია / რეესტრის ნდობა', $block);
        $this->assertStringContainsString('იპოთეკა / პრიორიტეტი / რეალიზაცია', $block);
        $this->assertStringContainsString('გადახდისუუნარობა / კრედიტორული მოთხოვნა', $block);
        $this->assertStringContainsString('მემკვიდრეობა / მეუღლეთა ქონება', $block);
        $this->assertStringContainsString('პრეიუდიცია / მტკიცების ტვირთი / თანამონაწილეობა', $block);
    }
}
