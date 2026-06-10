<?php

namespace Tests\Unit;

use App\DTOs\IssueList;
use App\DTOs\TriageResult;
use App\Services\Legal\LegalChatOrchestratorService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class LegalChatOrchestratorDecisionRolesTest extends TestCase
{
    public function test_it_marks_top_decisions_as_primary_and_rest_as_supporting(): void
    {
        config(['openai.primary_case_limit' => 2]);

        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'annotateDecisionRoles');

        $result = $method->invoke($service, [
            ['case_id' => 101],
            ['case_id' => 202],
            ['case_id' => 303],
            ['case_id' => 404],
        ]);

        $this->assertSame(['primary', 'primary', 'supporting', 'supporting'], array_column($result, 'answer_role'));
        $this->assertSame([1, 2, 3, 4], array_column($result, 'answer_rank'));
        $this->assertSame('მთავარი შესაბამისი საქმე', $result[0]['answer_role_label']);
        $this->assertSame('დამხმარე მსგავსი საქმე', $result[2]['answer_role_label']);
        $this->assertStringContainsString('supporting analogous practice', $result[2]['usage_instruction']);
    }

    public function test_it_marks_exact_case_card_issue_matches_as_primary(): void
    {
        config(['openai.primary_case_limit' => 2]);

        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'annotateDecisionRoles');

        $result = $method->invoke($service, [
            ['case_id' => 101],
            ['case_id' => 202],
            [
                'case_id' => 303,
                'semantic_relevance' => ['case_card_legal_issue_exact' => true],
            ],
            ['case_id' => 404],
        ]);

        $this->assertSame(['primary', 'primary', 'primary', 'supporting'], array_column($result, 'answer_role'));
        $this->assertStringContainsString('main authority', $result[2]['usage_instruction']);
    }

    public function test_it_discards_extracted_search_text_without_query_overlap(): void
    {
        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isUsableExtractedSearchText');

        $usable = $method->invoke(
            $service,
            'საქართველოს ფინანსთა სამინისტროს საკასაციო საჩივრის დასაშვებლობის საკითხი ადმინისტრაციულ საქმეთა პროცესში.',
            "სასამართლო\nსარჩელი\nმტკიცებულება\nსაპროცესო",
        );

        $this->assertFalse($usable);
    }

    public function test_it_keeps_extracted_search_text_with_query_overlap(): void
    {
        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isUsableExtractedSearchText');

        $usable = $method->invoke(
            $service,
            'საკასაციო საჩივრის დაუშვებლობის საკითხი და სახელმწიფო ბაჟის გადახდის ვალდებულება.',
            "საკასაციო საჩივარი\nსახელმწიფო ბაჟი",
        );

        $this->assertTrue($usable);
    }

    public function test_it_extracts_labeled_legal_issue_from_full_casus(): void
    {
        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'extractLabeledLegalIssue');

        $issue = 'საქართველოს ფინანსთა სამინისტროს საკასაციო საჩივრის დასაშვებობის საკითხი ადმინისტრაციულ საქმეთა პროცესში.';
        $question = "სრული კაზუსი:\nსაქმის არსი:\n{$issue}\nდამატებითი გარემოებები:\nმხარეები უთითებენ პროცესუალურ ხარვეზებზე.";

        $this->assertSame($issue, $method->invoke($service, $question));
    }

    public function test_court_ranking_query_prefers_labeled_issue_over_generic_extraction(): void
    {
        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'courtRankingQuery');

        $issue = 'საქართველოს ფინანსთა სამინისტროს საკასაციო საჩივრის დასაშვებობის საკითხი ადმინისტრაციულ საქმეთა პროცესში.';
        $question = "სრული კაზუსი:\nსაქმის არსი:\n{$issue}\nდავალება:\nმოძებნე სასამართლო პრაქტიკა და მომეცი დასკვნა.";
        $triage = $this->triageForCourt(searchQuery: "სასამართლო\nსარჩელი\nწყაროები");

        $this->assertSame($issue, $method->invoke($service, $triage, $triage->searchQuery, $question));
    }

    public function test_court_ranking_query_keeps_nearby_category_signal(): void
    {
        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'courtRankingQuery');

        $issue = 'საკასაციო საჩივრის დაუშვებლობის საკითხი და სახელმწიფო ბაჟის გადახდის ვალდებულება.';
        $category = 'დავის მიმართულება/კატეგორია: საგადასახადო ურთიერთობებიდან წარმოშობილი დავები';
        $question = "სრული კაზუსი:\nსაქმის არსი:\n{$issue}\n{$category}\nდამატებითი გარემოებები:\nმხარე უთითებს პროცედურულ ხარვეზებზე.";
        $triage = $this->triageForCourt(searchQuery: $issue);

        $result = $method->invoke($service, $triage, $triage->searchQuery, $question);

        $this->assertStringContainsString($issue, $result);
        $this->assertStringContainsString('საგადასახადო', $result);
    }

    public function test_long_full_casus_uses_focused_query_for_court_embedding(): void
    {
        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'shouldUseFocusedCourtQuery');

        $issue = 'საკასაციო საჩივრის დაუშვებლობა და სახელმწიფო ბაჟის გადახდის ვალდებულება.';
        $question = str_repeat('ფაქტობრივი გარემოებები და პროცესუალური ისტორია. ', 20)
            . "\nსაქმის არსი:\n{$issue}";
        $triage = $this->triageForCourt(searchQuery: $issue);

        $this->assertTrue($method->invoke($service, $triage, $issue, $question));
    }

    public function test_generic_court_extraction_is_not_substantive(): void
    {
        $service = (new ReflectionClass(LegalChatOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isSubstantiveCourtSearchText');

        $this->assertFalse($method->invoke($service, 'სასამართლო წყაროები დასკვნა'));
    }

    private function triageForCourt(string $searchQuery): TriageResult
    {
        return new TriageResult(
            intent: 'search',
            mode: 'explain',
            caseType: 'administrative',
            domains: ['admin'],
            issueList: IssueList::empty(),
            searchQuery: $searchQuery,
            needsNorms: false,
            needsCases: true,
            needsConstCourt: false,
            needsEu: false,
            needsGerman: false,
            temporalYear: null,
            isComplex: true,
            complexityScore: 90,
            complexityLevel: 'full',
            complexityReasons: ['long_fact_pattern'],
        );
    }
}
