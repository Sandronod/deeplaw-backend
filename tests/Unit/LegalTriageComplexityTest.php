<?php

namespace Tests\Unit;

use App\DTOs\IssueList;
use App\Services\Legal\LegalTriageService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class LegalTriageComplexityTest extends TestCase
{
    public function test_it_marks_short_exact_article_question_as_fast(): void
    {
        $result = $this->classify(
            question: 'შრომის კოდექსის 48-ე მუხლი განმიმარტე',
            mode: 'explain',
            domains: ['labor'],
            needsNorms: true,
            needsCases: false,
            activeSources: ['matsne'],
        );

        $this->assertSame('fast', $result['level']);
        $this->assertLessThanOrEqual(30, $result['score']);
        $this->assertContains('exact_article_reference', $result['reasons']);
        $this->assertContains('single_source', $result['reasons']);
    }

    public function test_it_marks_short_exact_case_lookup_as_fast(): void
    {
        $result = $this->classify(
            question: 'მიპოვე საქმე ბს-189(კ-25)',
            mode: 'find',
            domains: [],
            needsNorms: false,
            needsCases: true,
            activeSources: ['court'],
        );

        $this->assertSame('fast', $result['level']);
        $this->assertContains('exact_case_number', $result['reasons']);
    }

    public function test_it_marks_fact_heavy_multi_issue_question_as_full(): void
    {
        $question = str_repeat(
            'კაზუსი: დამსაქმებელმა თანამშრომელი გაათავისუფლა, არის რამდენიმე ფაქტი, სარჩელი და შეფასების საჭიროება. ',
            8,
        );

        $result = $this->classify(
            question: $question,
            mode: 'advise',
            domains: ['labor', 'procedure'],
            issueList: new IssueList([], 3, true),
            needsNorms: true,
            needsCases: true,
            activeSources: ['court', 'matsne', 'echr'],
        );

        $this->assertSame('full', $result['level']);
        $this->assertGreaterThanOrEqual(61, $result['score']);
        $this->assertContains('long_fact_pattern', $result['reasons']);
        $this->assertContains('multiple_issues', $result['reasons']);
        $this->assertContains('norms_and_cases', $result['reasons']);
    }

    public function test_it_routes_simple_exact_article_lookup_as_norm_only(): void
    {
        $this->assertTrue($this->simpleDomesticNormLookup(
            'შრომის კოდექსის 48-ე მუხლი განმიმარტე',
            'explain',
        ));
    }

    public function test_it_does_not_route_court_practice_article_question_as_norm_only(): void
    {
        $this->assertFalse($this->simpleDomesticNormLookup(
            'სამოქალაქო კოდექსის 54-ე მუხლზე სასამართლო პრაქტიკა მომიძებნე',
            'find',
        ));
    }

    private function classify(
        string $question,
        string $mode,
        array $domains,
        ?IssueList $issueList = null,
        bool $needsNorms = true,
        bool $needsCases = false,
        array $activeSources = [],
    ): array {
        $service = (new ReflectionClass(LegalTriageService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'classifyComplexity');

        return $method->invoke(
            $service,
            $question,
            $mode,
            $domains,
            $issueList ?? IssueList::empty(),
            $needsNorms,
            $needsCases,
            $activeSources,
        );
    }

    private function simpleDomesticNormLookup(string $question, string $mode): bool
    {
        $service = (new ReflectionClass(LegalTriageService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isSimpleDomesticNormLookup');

        return $method->invoke($service, $question, $mode, true);
    }
}
