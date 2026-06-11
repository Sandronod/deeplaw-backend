<?php

namespace Tests\Unit;

use App\Services\AI\LegalConsequenceTaxonomyService;
use App\Services\AI\LegalFactExtractorService;
use PHPUnit\Framework\TestCase;

class LegalConsequenceTaxonomyServiceTest extends TestCase
{
    public function test_it_evaluates_custom_threshold_rules_with_generic_operator_logic(): void
    {
        $service = new LegalConsequenceTaxonomyService([
            'example.minimum_claim_value' => [
                'article' => 'ტესტი 1',
                'condition' => 'claim_value >= 50000',
                'fact_keys' => ['claim_value'],
                'operator' => '>=',
                'threshold' => 50000,
                'outcome_true' => 'example.large_claim',
                'outcome_false' => 'example.small_claim',
                'boundary_rule' => 'equal_included',
                'category' => 'procedural_outcome.test',
                'prompt_true' => 'ოდენობა მინიმალურ ზღვარს აღწევს.',
                'prompt_false' => 'ოდენობა მინიმალურ ზღვარს ვერ აღწევს.',
            ],
        ], new LegalFactExtractorService());

        $applied = $service->applyRuleAtoms('სარჩელის ფასია 20 000 ლარი.');

        $this->assertCount(1, $applied);
        $this->assertSame('example.minimum_claim_value', $applied[0]['key']);
        $this->assertSame('example.small_claim', $applied[0]['outcome']);
        $this->assertSame('20 000 < 50 000', $applied[0]['reason']);
        $this->assertSame('ოდენობა მინიმალურ ზღვარს ვერ აღწევს.', $applied[0]['instruction']);
    }

    public function test_summary_lines_are_rendered_from_rule_registry(): void
    {
        $service = new LegalConsequenceTaxonomyService([
            'example.summary' => [
                'article' => 'ტესტი 2',
                'threshold' => 125000,
                'summary_lines' => [
                    'ზღვარი არის {threshold} ({article})',
                ],
            ],
        ]);

        $this->assertSame(['ზღვარი არის 125 000 (ტესტი 2)'], $service->summaryLines('example.summary'));
    }

    public function test_prompt_guidance_lines_include_registry_guards(): void
    {
        $service = new LegalConsequenceTaxonomyService([
            'example.guidance' => [
                'article' => 'Test Article 3',
                'threshold' => 50000,
                'summary_lines' => [
                    'Limit is {threshold} ({article})',
                ],
                'prompt_guard_lines' => [
                    'Do not confuse {article} with another rule.',
                ],
            ],
        ]);

        $this->assertSame([
            'Limit is 50 000 (Test Article 3)',
            'Do not confuse Test Article 3 with another rule.',
        ], $service->promptGuidanceLines('example.guidance'));
    }

    public function test_prompt_guidance_lines_for_question_match_triggers_and_category(): void
    {
        $service = new LegalConsequenceTaxonomyService([
            'example.procedure' => [
                'article' => 'Procedure Article',
                'category' => 'procedural_outcome.example',
                'trigger_any_keywords' => ['counterclaim'],
                'summary_lines' => ['Use {article}.'],
                'prompt_guard_lines' => ['Keep the procedure frame.'],
            ],
            'example.substantive' => [
                'article' => 'Substantive Article',
                'category' => 'substantive_outcome.example',
                'trigger_any_keywords' => ['counterclaim'],
                'summary_lines' => ['Do not include this line.'],
            ],
        ]);

        $this->assertSame([
            'Use Procedure Article.',
            'Keep the procedure frame.',
        ], $service->promptGuidanceLinesForQuestion('Can the counterclaim proceed?', null, 'procedural_outcome.'));
    }

    public function test_default_registry_detects_counterclaim_preparatory_stage_guard(): void
    {
        $service = new LegalConsequenceTaxonomyService();

        $applied = $service->applyRuleAtoms(
            'მოსამზადებელ სხდომაზე მოპასუხემ შემოიტანა შეგებებული სარჩელი.'
        );

        $keys = array_column($applied, 'key');

        $this->assertContains('civil_procedure.counterclaim_preparatory_stage_guard', $keys);
        $this->assertContains('civil_procedure.counterclaim_subject_matter_guard', $keys);
    }
}
