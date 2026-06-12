<?php

namespace Tests\Unit;

use App\DTOs\ConfidenceResult;
use App\DTOs\EchrResult;
use App\DTOs\IssueList;
use App\DTOs\TriageResult;
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
        $this->assertStringContainsString('AUTHORITY_STATUS: echr_interpretive_authority', $context);
    }

    public function test_it_labels_comparative_sources_as_non_binding(): void
    {
        $service = $this->app->make(OpenAILegalAnswerService::class);
        $method = new ReflectionMethod($service, 'buildContextBlock');

        $context = $method->invoke(
            $service,
            [],
            0,
            'explain',
            [],
            [],
            [],
            [
                [
                    'doc_type' => 'judgment',
                    'title' => 'CJEU example',
                    'court' => 'CJEU',
                    'case_num' => 'C-1/20',
                    'doc_date' => '2020-01-01',
                    'similarity' => 0.72,
                    'url' => 'https://example.test/eu',
                    'excerpt' => 'EU source excerpt.',
                ],
            ],
            [
                [
                    'court_name' => 'BGH',
                    'level_of_appeal' => 'Federal',
                    'date_year' => 2020,
                    'similarity' => 0.71,
                    'excerpt' => 'German source excerpt.',
                ],
            ],
            [],
        );

        $this->assertStringContainsString('AUTHORITY_STATUS: comparative_non_binding', $context);
        $this->assertStringContainsString('ქართულ სამართალში არ არის სავალდებულო', $context);
    }

    public function test_procedure_domain_includes_magistrate_subject_matter_guard(): void
    {
        $service = $this->app->make(OpenAILegalAnswerService::class);
        $method = new ReflectionMethod($service, 'buildSystemPrompt');

        $triage = new TriageResult(
            intent: 'search',
            mode: 'explain',
            caseType: 'any',
            domains: ['procedure'],
            issueList: IssueList::empty(),
            searchQuery: 'მაგისტრატი მოსამართლე სარჩელის ფასი სსკ 9',
            needsNorms: true,
            needsCases: false,
            needsConstCourt: false,
            needsEu: false,
            needsGerman: false,
            temporalYear: null,
            isComplex: false,
            complexityScore: 20,
            complexityLevel: 'fast',
            complexityReasons: ['simple_rule_application'],
        );

        $prompt = $method->invoke(
            $service,
            'explain',
            new ConfidenceResult(0.0, 'none', ''),
            ['matsne'],
            true,
            IssueList::empty(),
            'ზუსტად 50 000 ლარი ნიშნავს თუ არა მაგისტრატის ზღვრის გადაცილებას?',
            $triage,
        );

        $this->assertStringContainsString('მაგისტრატი მოსამართლე', $prompt);
        $this->assertStringContainsString('სსკ 9', $prompt);
        $this->assertStringContainsString('არ აურიო ეს საკითხი სააპელაციო საჩივრის დასაშვებობის ზღვართან', $prompt);
    }
}
