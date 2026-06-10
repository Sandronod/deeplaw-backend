<?php

namespace Tests\Unit;

use App\Services\AI\RerankerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RerankerServiceTest extends TestCase
{
    public function test_direct_match_sources_are_pinned_before_llm_rerank(): void
    {
        $service = new RerankerService();

        $decisions = [
            [
                'case_id' => 1,
                'case_num' => 'A-1',
                'relevance_score' => 0.80,
                'match_sources' => ['chunk_vector'],
            ],
            [
                'case_id' => 2,
                'case_num' => 'A-2',
                'relevance_score' => 0.99,
                'match_sources' => ['pasted_text'],
            ],
            [
                'case_id' => 3,
                'case_num' => 'A-3',
                'relevance_score' => 0.95,
                'match_sources' => ['case_number'],
            ],
            [
                'case_id' => 4,
                'case_num' => 'A-4',
                'relevance_score' => 0.88,
                'match_sources' => ['case_embedding'],
            ],
        ];

        $result = $service->rerank('query text', $decisions, 2);

        $this->assertSame([3, 2], array_column($result, 'case_id'));
    }

    public function test_pinned_decision_stays_first_when_llm_fills_remaining_slots(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '404,101,303']],
                ],
            ]),
        ]);

        $service = new RerankerService();

        $decisions = [
            [
                'case_id' => 101,
                'case_num' => 'A-1',
                'relevance_score' => 0.93,
                'match_sources' => ['case_embedding'],
            ],
            [
                'case_id' => 202,
                'case_num' => 'A-2',
                'relevance_score' => 1.0,
                'match_sources' => ['pasted_text'],
            ],
            [
                'case_id' => 303,
                'case_num' => 'A-3',
                'relevance_score' => 0.91,
                'match_sources' => ['chunk_vector'],
            ],
            [
                'case_id' => 404,
                'case_num' => 'A-4',
                'relevance_score' => 0.90,
                'match_sources' => ['case_embedding'],
            ],
        ];

        $result = $service->rerank('query text', $decisions, 3);

        $this->assertSame([202, 404, 101], array_column($result, 'case_id'));
    }

    public function test_weak_pasted_text_match_is_not_pinned(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '303,202']],
                ],
            ]),
        ]);

        $service = new RerankerService();

        $decisions = [
            [
                'case_id' => 101,
                'case_num' => 'A-1',
                'relevance_score' => 1.0,
                'match_sources' => ['case_number'],
            ],
            [
                'case_id' => 202,
                'case_num' => 'A-2',
                'relevance_score' => 0.70,
                'match_sources' => ['pasted_text'],
            ],
            [
                'case_id' => 303,
                'case_num' => 'A-3',
                'relevance_score' => 0.90,
                'match_sources' => ['case_embedding'],
            ],
        ];

        $result = $service->rerank('query text', $decisions, 2);

        $this->assertSame([101, 303], array_column($result, 'case_id'));
    }

    public function test_high_confidence_semantic_match_is_pinned(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '303,101']],
                ],
            ]),
        ]);

        $service = new RerankerService();

        $decisions = [
            [
                'case_id' => 101,
                'case_num' => 'A-101',
                'relevance_score' => 0.91,
                'match_sources' => ['case_embedding'],
                'semantic_relevance_score' => 76,
                'semantic_relevance' => ['confidence' => 'medium'],
            ],
            [
                'case_id' => 202,
                'case_num' => 'A-202',
                'relevance_score' => 0.82,
                'match_sources' => ['case_embedding'],
                'semantic_relevance_score' => 88,
                'semantic_relevance' => ['confidence' => 'high'],
            ],
            [
                'case_id' => 303,
                'case_num' => 'A-303',
                'relevance_score' => 0.80,
                'match_sources' => ['chunk_vector'],
                'semantic_relevance_score' => 70,
                'semantic_relevance' => ['confidence' => 'medium'],
            ],
        ];

        $result = $service->rerank('query text', $decisions, 2);

        $this->assertSame([202, 303], array_column($result, 'case_id'));
    }

    public function test_top_exact_case_card_issue_match_is_pinned(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '303,101']],
                ],
            ]),
        ]);

        $service = new RerankerService();

        $decisions = [
            [
                'case_id' => 101,
                'case_num' => 'A-101',
                'relevance_score' => 0.92,
                'retrieval_rank' => 1,
                'match_sources' => ['case_card_keyword'],
                'semantic_relevance_score' => 62,
                'semantic_relevance' => [
                    'confidence' => 'medium',
                    'case_card_legal_issue_exact' => true,
                ],
            ],
            [
                'case_id' => 202,
                'case_num' => 'A-202',
                'relevance_score' => 0.90,
                'retrieval_rank' => 4,
                'match_sources' => ['case_card_keyword'],
                'semantic_relevance_score' => 60,
                'semantic_relevance' => [
                    'confidence' => 'medium',
                    'case_card_legal_issue_exact' => true,
                ],
            ],
            [
                'case_id' => 303,
                'case_num' => 'A-303',
                'relevance_score' => 0.99,
                'retrieval_rank' => 9,
                'match_sources' => ['case_embedding'],
                'semantic_relevance_score' => 70,
                'semantic_relevance' => ['confidence' => 'medium'],
            ],
        ];

        $result = $service->rerank('query text', $decisions, 2);

        $this->assertSame([101, 202], array_column($result, 'case_id'));
    }
}
