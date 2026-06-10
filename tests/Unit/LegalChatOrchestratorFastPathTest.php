<?php

namespace Tests\Unit;

use App\DTOs\IssueList;
use App\DTOs\TriageResult;
use App\Services\Legal\LegalChatOrchestratorService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class LegalChatOrchestratorFastPathTest extends TestCase
{
    public function test_fast_exact_article_explanation_skips_rule_extraction(): void
    {
        $service = $this->service();
        $triage = $this->triage('fast');
        $docs = [$this->articleDoc(48)];

        $this->assertFalse($this->invoke($service, 'shouldExtractRules', [
            $triage,
            'შრომის კოდექსის 48-ე მუხლი განმიმარტე',
            $docs,
        ]));

        $this->assertFalse($this->invoke($service, 'shouldUseSemanticNormSupplement', [
            $triage,
            $docs,
            'შრომის კოდექსის 48-ე მუხლი განმიმარტე',
        ]));
    }

    public function test_fast_exact_article_with_concrete_rule_request_keeps_rule_extraction(): void
    {
        $service = $this->service();

        $this->assertTrue($this->invoke($service, 'shouldExtractRules', [
            $this->triage('fast'),
            'შრომის კოდექსის 48-ე მუხლში რა ვადაა მითითებული?',
            [$this->articleDoc(48)],
        ]));
    }

    public function test_normal_concept_question_keeps_rule_extraction(): void
    {
        $service = $this->service();

        $this->assertTrue($this->invoke($service, 'shouldExtractRules', [
            $this->triage('normal'),
            'დამსაქმებელმა გაათავისუფლა წერილობითი დასაბუთების გარეშე, რომელი ნორმები უნდა ვნახო?',
            [$this->articleDoc(47), $this->articleDoc(48)],
        ]));
    }

    private function service(): LegalChatOrchestratorService
    {
        return (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
    }

    private function invoke(LegalChatOrchestratorService $service, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($service, $method);

        return $reflection->invokeArgs($service, $args);
    }

    private function triage(string $complexityLevel): TriageResult
    {
        return new TriageResult(
            intent: 'search',
            mode: 'explain',
            caseType: 'civil',
            domains: ['labor'],
            issueList: IssueList::empty(),
            searchQuery: '',
            needsNorms: true,
            needsCases: false,
            needsConstCourt: false,
            needsEu: false,
            needsGerman: false,
            temporalYear: null,
            isComplex: $complexityLevel === 'full',
            complexityScore: $complexityLevel === 'fast' ? 20 : 45,
            complexityLevel: $complexityLevel,
            complexityReasons: [],
        );
    }

    private function articleDoc(int $articleNum): array
    {
        return [
            '_source' => 'article_detector',
            '_article_num' => $articleNum,
            'title' => 'Test law',
            'excerpt' => 'Test article text',
            'matsne_id' => 1,
            'similarity' => 0.95,
        ];
    }
}
