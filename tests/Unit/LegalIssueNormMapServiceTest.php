<?php

namespace Tests\Unit;

use App\Services\Legal\LegalIssueNormMapService;
use PHPUnit\Framework\TestCase;

class LegalIssueNormMapServiceTest extends TestCase
{
    public function test_it_matches_magistrate_claim_value_issue_from_registry(): void
    {
        $matches = $this->service()->match(
            'შეგებებული სარჩელის ფასია ზუსტად 50 000 ლარი და საკითხია მაგისტრატი მოსამართლის განსჯადობა.',
            ['procedure'],
        );

        $this->assertSame('civil_procedure.magistrate_claim_value', $matches[0]['key']);
        $this->assertSame('equal_included', $matches[0]['boundary_rule']);
        $this->assertSame(
            [['law' => 'civil_procedure_code', 'articles' => [9]]],
            $matches[0]['required_sources'],
        );
    }

    public function test_it_matches_multiple_large_casus_issues(): void
    {
        $matches = $this->service()->match(
            'მყიდველებს ბინის ფასი გადახდილი აქვთ, მაგრამ საკუთრება საჯარო რეესტრში არ არის რეგისტრირებული. ბანკს აქვს იპოთეკა, კომპანია გაკოტრების ზღვარზეა, მყიდველი გარდაიცვალა, მეუღლე აცხადებს თანასაკუთრებას, აღძრულია თაღლითობის საქმე და 240 მყიდველი ითხოვს ერთობლივ სარჩელს.',
            ['civil', 'property', 'family'],
        );

        $keys = array_column($matches, 'key');

        $this->assertContains('property.real_estate_registration', $keys);
        $this->assertContains('property.mortgage_priority_enforcement', $keys);
        $this->assertContains('insolvency.creditor_status', $keys);
        $this->assertContains('family.inheritance_estate_claims', $keys);
        $this->assertContains('family.marital_property_share', $keys);
        $this->assertContains('civil_procedure.criminal_preclusion', $keys);
        $this->assertContains('civil_procedure.joinder', $keys);
    }

    public function test_it_ignores_unrelated_question(): void
    {
        $this->assertSame([], $this->service()->match('რა არის სამართალი ზოგადად?'));
    }

    private function service(): LegalIssueNormMapService
    {
        $config = require __DIR__ . '/../../config/legal_issue_norms.php';

        return new LegalIssueNormMapService($config['issues']);
    }
}
