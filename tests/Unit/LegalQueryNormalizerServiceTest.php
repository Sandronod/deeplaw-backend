<?php

namespace Tests\Unit;

use App\Services\AI\LegalConsequenceTaxonomyService;
use App\Services\AI\LegalFactExtractorService;
use App\Services\AI\LegalQueryNormalizerService;
use PHPUnit\Framework\TestCase;

class LegalQueryNormalizerServiceTest extends TestCase
{
    public function test_it_expands_counterclaim_magistrate_and_property_terms(): void
    {
        $normalizer = new LegalQueryNormalizerService(
            new LegalFactExtractorService(),
            new LegalConsequenceTaxonomyService(),
        );

        $result = $normalizer->normalize(
            'ძირითადი სარჩელით მოთხოვნილია უკანონო მფლობელობიდან ნივთის გამოთხოვა. სარჩელი მიღებულია წარმოებაში მაგისტრატი მოსამართლის მიერ. მოსამზადებელ სხდომაზე მოპასუხემ შემოიტანა შეგებებული სარჩელი და ითხოვს მესაკუთრედ ცნობას, თუმცა სარჩელის ფასია 50 000 ლარი.'
        );

        $this->assertTrue($result['changed']);
        $this->assertContains('ვინდიკაციური სარჩელი', $result['added_terms']);
        $this->assertContains('საკუთრების უფლების აღიარება', $result['added_terms']);
        $this->assertContains('სსკ 9', $result['added_terms']);
        $this->assertContains('სსკ 188', $result['added_terms']);
        $this->assertContains('civil_procedure.magistrate_claim_value', $result['rule_triggers']);
        $this->assertContains('civil_procedure.counterclaim_preparatory_stage_guard', $result['rule_triggers']);
        $this->assertContains('procedural_outcome.subject_matter_jurisdiction', $result['outcome_categories']);
    }
}
