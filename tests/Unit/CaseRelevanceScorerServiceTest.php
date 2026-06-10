<?php

namespace Tests\Unit;

use App\Services\AI\CaseRelevanceScorerService;
use Tests\TestCase;

class CaseRelevanceScorerServiceTest extends TestCase
{
    public function test_substantive_legal_issue_match_beats_generic_high_retrieval_score(): void
    {
        $service = new CaseRelevanceScorerService();

        $query = 'საკასაციო საჩივრის დაუშვებლობა და სახელმწიფო ბაჟის გადახდის ვალდებულება ადმინისტრაციულ დავაში';

        $decisions = [
            [
                'case_id' => 101,
                'case_num' => 'A-101',
                'relevance_score' => 0.95,
                'combined_score' => 0.90,
                'match_sources' => ['case_embedding'],
                'category' => 'ზიანის ანაზღაურების დავა',
                'dispute_subject' => 'ზიანის ანაზღაურება',
                'claim_type' => 'სარჩელი ზიანის ანაზღაურებაზე',
                'kind' => '',
                'result' => 'სარჩელი ნაწილობრივ დაკმაყოფილდა',
                'excerpt' => 'საქმე ეხება ზიანის ანაზღაურებას და მტკიცებულებების შეფასებას.',
                'case_card' => [
                    'legal_issue' => 'ზიანის ანაზღაურების ვალდებულება რამდენიმე მოვალის მიმართ.',
                    'holding' => 'სასამართლომ იმსჯელა ზიანის ანაზღაურების წინაპირობებზე.',
                    'applied_articles' => ['სსკ 992'],
                ],
            ],
            [
                'case_id' => 202,
                'case_num' => 'A-202',
                'relevance_score' => 0.72,
                'combined_score' => 0.66,
                'match_sources' => ['case_embedding'],
                'category' => 'საგადასახადო ურთიერთობებიდან წარმოშობილი დავები',
                'dispute_subject' => 'სახელმწიფო ბაჟის გადახდის ვალდებულება',
                'claim_type' => 'საკასაციო საჩივარი',
                'kind' => '',
                'result' => 'საკასაციო საჩივარი დაუშვებლად იქნა ცნობილი',
                'excerpt' => 'საკასაციო სასამართლომ იმსჯელა საჩივრის დაუშვებლობასა და სახელმწიფო ბაჟის დაბრუნებაზე.',
                'case_card' => [
                    'legal_issue' => 'საკასაციო საჩივრის დაუშვებლობა და სახელმწიფო ბაჟის გადახდის ვალდებულება ადმინისტრაციულ სამართალში.',
                    'holding' => 'საკასაციო სასამართლომ საჩივარი დაუშვებლად მიიჩნია და შეაფასა სახელმწიფო ბაჟის საკითხი.',
                    'applied_articles' => ['საქართველოს ადმინისტრაციული საპროცესო კოდექსის 34-ე მუხლი', 'სსკ 401'],
                ],
            ],
        ];

        $scored = $service->score($query, $decisions);

        $this->assertSame(202, $scored[0]['case_id']);
        $this->assertGreaterThan($scored[1]['semantic_relevance_score'], $scored[0]['semantic_relevance_score']);
        $this->assertStringContainsString('legal issue', $scored[0]['ranking_explanation']);
    }

    public function test_exact_case_card_legal_issue_is_not_diluted_by_metadata(): void
    {
        $service = new CaseRelevanceScorerService();

        $query = 'alpha beta gamma delta';

        $scored = $service->score($query, [
            [
                'case_id' => 303,
                'case_num' => 'A-303',
                'relevance_score' => 0.80,
                'combined_score' => 0.70,
                'match_sources' => ['case_card_keyword'],
                'category' => 'unrelated category noise',
                'dispute_subject' => 'unrelated subject noise',
                'claim_type' => 'unrelated claim noise',
                'kind' => '',
                'result' => '',
                'excerpt' => '',
                'case_card' => [
                    'legal_issue' => $query,
                    'holding' => '',
                    'applied_articles' => [],
                ],
            ],
        ]);

        $this->assertSame(303, $scored[0]['case_id']);
        $this->assertSame(40.0, $scored[0]['semantic_relevance']['legal_issue_match']);
        $this->assertTrue($scored[0]['semantic_relevance']['case_card_legal_issue_exact']);
    }

    public function test_direct_case_number_match_gets_high_confidence_even_with_sparse_text(): void
    {
        $service = new CaseRelevanceScorerService();

        $scored = $service->score('მომიძებნე საქმე ბს-189(კ-25)', [
            [
                'case_id' => 34638,
                'case_num' => 'ბს-189(კ-25)',
                'relevance_score' => 0.20,
                'combined_score' => 0.10,
                'match_sources' => ['case_number'],
                'category' => '',
                'dispute_subject' => '',
                'claim_type' => '',
                'kind' => '',
                'result' => '',
                'excerpt' => '',
                'case_card' => [],
            ],
            [
                'case_id' => 999,
                'case_num' => 'A-999',
                'relevance_score' => 0.90,
                'combined_score' => 0.90,
                'match_sources' => ['case_embedding'],
                'category' => 'სხვა დავა',
                'dispute_subject' => 'სხვა საკითხი',
                'claim_type' => '',
                'kind' => '',
                'result' => '',
                'excerpt' => 'სხვა საკითხი',
                'case_card' => [
                    'legal_issue' => 'სხვა საკითხი',
                    'holding' => 'სხვა დასკვნა',
                    'applied_articles' => [],
                ],
            ],
        ]);

        $this->assertSame(34638, $scored[0]['case_id']);
        $this->assertSame('high', $scored[0]['semantic_relevance']['confidence']);
        $this->assertSame(99.0, $scored[0]['semantic_relevance']['direct_match_boost']);
    }
}
