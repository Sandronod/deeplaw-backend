<?php

namespace Tests\Unit;

use App\DTOs\IssueList;
use App\Services\AI\IntentClassifierService;
use App\Services\AI\IssueSpotterService;
use App\Services\AI\QueryExtractorService;
use App\Services\Legal\LegalDomainClassifier;
use App\Services\Legal\LegalTriageService;
use Tests\TestCase;

class LegalTriageSourceRoutingTest extends TestCase
{
    public function test_selected_german_source_prevents_domestic_norm_only_routing(): void
    {
        $result = $this->triage()->triage(
            'შრომის კოდექსის 48-ე მუხლი განმიმარტე',
            ['matsne', 'german'],
        );

        $this->assertTrue($result->needsNorms);
        $this->assertTrue($result->needsGerman);
        $this->assertFalse($result->needsCases);
    }

    public function test_domestic_only_exact_article_lookup_stays_norm_only(): void
    {
        $result = $this->triage()->triage(
            'შრომის კოდექსის 48-ე მუხლი განმიმარტე',
            ['court', 'matsne'],
        );

        $this->assertTrue($result->needsNorms);
        $this->assertFalse($result->needsCases);
        $this->assertFalse($result->needsGerman);
    }

    public function test_explicit_german_practice_request_enables_german_source_even_with_default_sources(): void
    {
        $result = $this->triage()->triage(
            'გერმანიის სასამართლო პრაქტიკა შრომითი ხელშეკრულების შეწყვეტაზე მომიძებნე',
            ['court', 'matsne'],
        );

        $this->assertTrue($result->needsGerman);
    }

    public function test_selected_eu_source_prevents_domestic_norm_only_routing(): void
    {
        $result = $this->triage()->triage(
            'შრომის კოდექსის 48-ე მუხლი განმიმარტე',
            ['matsne', 'eu'],
        );

        $this->assertTrue($result->needsEu);
    }

    public function test_explicit_eu_request_enables_eu_source_even_with_default_sources(): void
    {
        $result = $this->triage()->triage(
            'ევროკავშირის სამართლის პრაქტიკა მომხმარებელთა დაცვის საკითხზე მომიძებნე',
            ['court', 'matsne'],
        );

        $this->assertTrue($result->needsEu);
    }

    public function test_selected_const_court_source_prevents_domestic_norm_only_routing(): void
    {
        $result = $this->triage()->triage(
            'სამოქალაქო კოდექსის 183-ე მუხლი განმიმარტე',
            ['matsne', 'const_court'],
        );

        $this->assertTrue($result->needsConstCourt);
    }

    public function test_explicit_const_court_request_enables_const_court_source_even_with_default_sources(): void
    {
        $result = $this->triage()->triage(
            'საკონსტიტუციო სასამართლოს პრაქტიკა საკუთრების უფლებაზე მომიძებნე',
            ['court', 'matsne'],
        );

        $this->assertTrue($result->needsConstCourt);
    }

    private function triage(): LegalTriageService
    {
        return new LegalTriageService(
            new IntentClassifierService(),
            new class extends IssueSpotterService {
                public function __construct() {}

                public function spot(string $userQuestion): IssueList
                {
                    return IssueList::empty();
                }
            },
            new class extends QueryExtractorService {
                public function __construct() {}

                public function extractWithDomain(string $userMessage): array
                {
                    return [
                        'query' => $userMessage,
                        'domain' => 'labor',
                        'normalization' => [],
                    ];
                }
            },
            new LegalDomainClassifier(),
        );
    }
}
