<?php

namespace Tests\Unit;

use App\DTOs\IssueList;
use App\DTOs\TriageResult;
use App\Services\AI\OpenAILegalAnswerService;
use ReflectionMethod;
use Tests\TestCase;

class OpenAILegalAnswerModelRoutingTest extends TestCase
{
    public function test_it_uses_default_mini_model_for_small_questions(): void
    {
        config([
            'openai.chat_model' => 'gpt-4.1-mini',
            'openai.complex_chat_model' => 'gpt-4.1',
            'openai.dynamic_chat_model_enabled' => true,
        ]);

        $model = $this->modelFor(
            question: 'Explain article 48.',
            triage: $this->triage(level: 'fast', score: 22),
        );

        $this->assertSame('gpt-4.1-mini', $model);
    }

    public function test_it_uses_complex_model_for_full_casus(): void
    {
        config([
            'openai.chat_model' => 'gpt-4.1-mini',
            'openai.complex_chat_model' => 'gpt-4.1',
            'openai.dynamic_chat_model_enabled' => true,
        ]);

        $model = $this->modelFor(
            question: 'Large legal casus: ' . str_repeat('Factual background and legal issue. ', 30),
            triage: $this->triage(level: 'full', score: 82),
        );

        $this->assertSame('gpt-4.1', $model);
    }

    public function test_it_can_disable_dynamic_routing(): void
    {
        config([
            'openai.chat_model' => 'gpt-4.1-mini',
            'openai.complex_chat_model' => 'gpt-4.1',
            'openai.dynamic_chat_model_enabled' => false,
        ]);

        $model = $this->modelFor(
            question: 'Large legal casus: ' . str_repeat('Factual background. ', 60),
            triage: $this->triage(level: 'full', score: 90),
        );

        $this->assertSame('gpt-4.1-mini', $model);
    }

    private function modelFor(string $question, TriageResult $triage): string
    {
        $service = $this->app->make(OpenAILegalAnswerService::class);
        $method = new ReflectionMethod($service, 'modelForRequest');

        return $method->invoke($service, $question, 'explain', $triage, IssueList::empty());
    }

    private function triage(string $level, int $score): TriageResult
    {
        return new TriageResult(
            intent: 'search',
            mode: 'explain',
            caseType: 'any',
            domains: ['civil'],
            issueList: IssueList::empty(),
            searchQuery: 'test',
            needsNorms: true,
            needsCases: true,
            needsConstCourt: false,
            needsEu: false,
            needsGerman: false,
            temporalYear: null,
            isComplex: $level === 'full',
            complexityScore: $score,
            complexityLevel: $level,
            complexityReasons: [],
        );
    }
}
