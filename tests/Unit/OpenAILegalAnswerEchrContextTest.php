<?php

namespace Tests\Unit;

use App\DTOs\EchrResult;
use App\Services\AI\OpenAILegalAnswerService;
use ReflectionMethod;
use Tests\TestCase;

class OpenAILegalAnswerEchrContextTest extends TestCase
{
    public function test_it_formats_echr_result_dtos_for_the_answer_context(): void
    {
        $service = $this->app->make(OpenAILegalAnswerService::class);
        $method = new ReflectionMethod($service, 'buildContextBlock');

        $context = $method->invoke(
            $service,
            [],
            0,
            'explain',
            [],
            [
                new EchrResult(
                    caseId: 1,
                    hudocItemId: 'ITEM-1',
                    applicationNumber: '57292/16',
                    title: 'HURBAIN v. BELGIUM',
                    judgmentDate: '2023-07-04',
                    documentType: 'GRANDCHAMBER',
                    importance: 1,
                    echrArticles: ['10'],
                    excerpt: 'Freedom of expression and protection of reputation.',
                    similarity: 0.8,
                    sourceUrl: 'https://hudoc.echr.coe.int/example',
                ),
            ],
            [],
            [],
            [],
            [],
        );

        $this->assertStringContainsString('HURBAIN v. BELGIUM', $context);
        $this->assertStringContainsString('57292/16', $context);
        $this->assertStringContainsString('Articles: 10', $context);
        $this->assertStringContainsString('https://hudoc.echr.coe.int/example', $context);
    }
}
