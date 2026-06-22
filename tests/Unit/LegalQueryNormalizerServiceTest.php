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

    public function test_it_expands_large_casus_special_source_terms(): void
    {
        $normalizer = new LegalQueryNormalizerService(
            new LegalFactExtractorService(),
            new LegalConsequenceTaxonomyService(),
        );

        $result = $normalizer->normalize(
            'მონაცემთა გაჟონვის შემდეგ თანამშრომელს ედავებიან ზიანის ანაზღაურებას, არაკონკურენციის შეთანხმებას და 1 მლნ პირგასამტეხლოს.'
        );

        $this->assertTrue($result['changed']);
        $this->assertContains('პერსონალურ მონაცემთა დაცვის შესახებ', $result['added_terms']);
        $this->assertContains('მონაცემთა უსაფრთხოება', $result['added_terms']);
        $this->assertContains('შრომის კოდექსი 60', $result['added_terms']);
        $this->assertContains('სამოქალაქო კოდექსი 420', $result['added_terms']);
        $this->assertContains('მიზეზობრივი კავშირი', $result['added_terms']);
        $this->assertContains('administrative_outcome.personal_data_sanction', $result['outcome_categories']);
        $this->assertContains('substantive_outcome.penalty_reduction', $result['outcome_categories']);
    }

    public function test_it_expands_real_estate_development_casus_terms(): void
    {
        $normalizer = new LegalQueryNormalizerService(
            new LegalFactExtractorService(),
            new LegalConsequenceTaxonomyService(),
        );

        $result = $normalizer->normalize(
            'მყიდველებმა ბინის ფასი სრულად გადაიხადეს, მაგრამ საკუთრება საჯარო რეესტრში არ არის რეგისტრირებული. ბანკს აქვს იპოთეკა და იწყებს ქონების რეალიზაციას. კომპანია გაკოტრების ზღვარზეა, მყიდველი გარდაიცვალა, მეუღლე აცხადებს თანასაკუთრებას, აღძრულია თაღლითობის საქმე და 240 მყიდველს სურს კოლექტიური სარჩელი.'
        );

        $this->assertTrue($result['changed']);
        $this->assertContains('საჯარო რეესტრის პრეზუმფცია', $result['added_terms']);
        $this->assertContains('სკ 183', $result['added_terms']);
        $this->assertContains('სკ 312', $result['added_terms']);
        $this->assertContains('იპოთეკის პრიორიტეტი', $result['added_terms']);
        $this->assertContains('რეაბილიტაციისა და კრედიტორთა კოლექტიური დაკმაყოფილების შესახებ', $result['added_terms']);
        $this->assertContains('სამკვიდრო ქონება', $result['added_terms']);
        $this->assertContains('სსკ 106', $result['added_terms']);
        $this->assertContains('სსკ 86', $result['added_terms']);
        $this->assertContains('procedural_outcome.insolvency_claim_status', $result['outcome_categories']);
        $this->assertContains('procedural_outcome.preclusion', $result['outcome_categories']);
    }
}
