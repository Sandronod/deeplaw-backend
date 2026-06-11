<?php

namespace Tests\Unit;

use App\Contracts\AnswerServiceInterface;
use App\DTOs\ConfidenceResult;
use App\DTOs\IssueList;
use App\DTOs\RetrievalResult;
use App\DTOs\TriageResult;
use App\Services\AI\AnswerPostProcessorService;
use App\Services\AI\AnswerValidatorService;
use App\Services\Legal\LegalChatOrchestratorService;
use ReflectionClass;
use Tests\TestCase;

class AnswerCorrectionGateTest extends TestCase
{
    public function test_validation_gate_retries_once_and_uses_corrected_answer(): void
    {
        config(['openai.answer_correction_enabled' => true]);

        $answerer = new FakeCorrectionAnswerService(['Corrected answer']);

        $validator = $this->createMock(AnswerValidatorService::class);
        $validator->expects($this->exactly(2))
            ->method('validate')
            ->willReturnOnConsecutiveCalls(
                $this->validationResult('fail', 70, [[
                    'type' => 'unsupported_article',
                    'severity' => 'high',
                    'message' => 'Article is not grounded.',
                    'value' => '365',
                ]]),
                $this->validationResult('pass', 100, []),
            );

        $postProcessor = $this->createMock(AnswerPostProcessorService::class);
        $postProcessor->method('process')->willReturnCallback(
            fn (string $text, array $ctx = []) => ['text' => $text, 'changes' => []],
        );

        $service = $this->orchestrator($answerer, $validator, $postProcessor);

        $result = $service->applyValidationCorrectionGate($this->ctx(), 'Bad answer');

        $this->assertSame('Corrected answer', $result['text']);
        $this->assertTrue($result['meta']['attempted']);
        $this->assertTrue($result['meta']['corrected']);
        $this->assertSame('corrected', $result['meta']['status']);
        $this->assertStringContainsString('Original question', $answerer->questions[0]);
        $this->assertStringContainsString('Article is not grounded.', $answerer->questions[0]);
    }

    public function test_validation_gate_does_not_retry_clean_answer(): void
    {
        config(['openai.answer_correction_enabled' => true]);

        $answerer = new FakeCorrectionAnswerService(['Should not be called']);

        $validator = $this->createMock(AnswerValidatorService::class);
        $validator->expects($this->once())
            ->method('validate')
            ->willReturn($this->validationResult('pass', 100, []));

        $postProcessor = $this->createMock(AnswerPostProcessorService::class);
        $postProcessor->method('process')->willReturnCallback(
            fn (string $text, array $ctx = []) => ['text' => $text, 'changes' => []],
        );

        $service = $this->orchestrator($answerer, $validator, $postProcessor);

        $result = $service->applyValidationCorrectionGate($this->ctx(), 'Clean answer');

        $this->assertSame('Clean answer', $result['text']);
        $this->assertFalse($result['meta']['attempted']);
        $this->assertSame('not_needed', $result['meta']['status']);
        $this->assertSame([], $answerer->questions);
    }

    private function orchestrator(
        AnswerServiceInterface $answerer,
        AnswerValidatorService $validator,
        AnswerPostProcessorService $postProcessor,
    ): LegalChatOrchestratorService {
        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();

        $this->setPrivateProperty($service, 'answerer', $answerer);
        $this->setPrivateProperty($service, 'answerValidator', $validator);
        $this->setPrivateProperty($service, 'answerPostProcessor', $postProcessor);

        return $service;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    /**
     * @param array<int, array<string, mixed>> $flags
     * @return array<string, mixed>
     */
    private function validationResult(string $verdict, int $score, array $flags): array
    {
        return [
            'verdict' => $verdict,
            'score' => $score,
            'flags' => $flags,
            'summary' => [
                'flags_count' => count($flags),
                'high_flags' => count(array_filter($flags, fn (array $flag) => $flag['severity'] === 'high')),
                'medium_flags' => count(array_filter($flags, fn (array $flag) => $flag['severity'] === 'medium')),
                'low_flags' => count(array_filter($flags, fn (array $flag) => $flag['severity'] === 'low')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ctx(): array
    {
        return [
            'userQuestion' => 'Original question',
            'finalDecisions' => [],
            'history' => [],
            'retrieval' => RetrievalResult::empty(),
            'mode' => 'explain',
            'confidence' => new ConfidenceResult(0.0, 'none', ''),
            'echrResults' => [],
            'matsneResults' => [],
            'euResults' => [],
            'germanResults' => [],
            'constCourtResults' => [],
            'sources' => ['court', 'matsne'],
            'issueList' => IssueList::empty(),
            'triageResult' => new TriageResult(
                intent: 'search',
                mode: 'explain',
                caseType: 'civil',
                domains: ['procedure'],
                issueList: IssueList::empty(),
                searchQuery: '',
                needsNorms: true,
                needsCases: false,
                needsConstCourt: false,
                needsEu: false,
                needsGerman: false,
                temporalYear: null,
                isComplex: false,
                complexityScore: 20,
                complexityLevel: 'fast',
                complexityReasons: [],
            ),
            'extractedRules' => [],
        ];
    }
}

class FakeCorrectionAnswerService implements AnswerServiceInterface
{
    /**
     * @param array<int, string> $responses
     */
    public function __construct(private array $responses)
    {
    }

    /**
     * @var array<int, string>
     */
    public array $questions = [];

    public function answer(
        string $userQuestion,
        array $decisions,
        array $historyMessages = [],
        int $totalFound = 0,
        string $mode = 'explain',
        ConfidenceResult $confidence = new ConfidenceResult(0.0, 'none', ''),
        array $lawResults = [],
        array $echrResults = [],
        array $matsneResults = [],
        array $euResults = [],
        array $germanResults = [],
        array $constCourtResults = [],
        array $sources = [],
        ?IssueList $issueList = null,
        ?TriageResult $triage = null,
        array $extractedRules = [],
    ): string {
        $this->questions[] = $userQuestion;

        return array_shift($this->responses) ?? '';
    }

    public function streamTokens(
        string $userQuestion,
        array $decisions,
        array $historyMessages = [],
        int $totalFound = 0,
        string $mode = 'explain',
        ConfidenceResult $confidence = new ConfidenceResult(0.0, 'none', ''),
        array $lawResults = [],
        array $echrResults = [],
        array $matsneResults = [],
        array $euResults = [],
        array $germanResults = [],
        array $constCourtResults = [],
        array $sources = [],
        ?IssueList $issueList = null,
        ?TriageResult $triage = null,
        array $extractedRules = [],
    ): \Generator {
        if (false) {
            yield '';
        }
    }
}
