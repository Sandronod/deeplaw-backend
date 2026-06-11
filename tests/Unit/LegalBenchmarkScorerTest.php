<?php

namespace Tests\Unit;

use App\Services\Evaluation\LegalBenchmarkScorer;
use PHPUnit\Framework\TestCase;

class LegalBenchmarkScorerTest extends TestCase
{
    public function test_it_scores_laws_articles_cases_and_forbidden_hits(): void
    {
        $scorer = new LegalBenchmarkScorer();

        $score = $scorer->scoreScenario([
            'id' => 'labor_termination',
            'expected' => [
                'matsne' => [
                    ['matsne_id' => 1155567, 'articles' => [47, 48]],
                ],
                'case_ids' => [101],
                'rule_triggers' => ['labor.termination_notice'],
                'outcome_categories' => ['substantive_outcome.labor_termination'],
                'facts' => [
                    ['key' => 'notice_deadline', 'value' => 30, 'unit' => 'day'],
                ],
                'forbidden_matsne_ids' => [29962],
            ],
        ], [
            'matsne' => [
                ['matsne_id' => 1155567, '_article_num' => 47],
                ['matsne_id' => 1155567, '_article_num' => 48],
                ['matsne_id' => 29962, '_article_num' => 48],
            ],
            'case_ids' => [101, 202],
            'echr' => [],
            'rule_triggers' => ['labor.termination_notice'],
            'outcome_categories' => ['substantive_outcome.labor_termination'],
            'facts' => [
                ['key' => 'notice_deadline', 'value' => 30, 'unit' => 'day'],
            ],
        ]);

        $this->assertFalse($score['passed']);
        $this->assertSame(['law:29962'], $score['forbidden_hits']);
        $this->assertSame(1, $score['law']['matched']);
        $this->assertSame(2, $score['articles']['matched']);
        $this->assertSame(1, $score['cases']['matched']);
        $this->assertSame(1, $score['rule_triggers']['matched']);
        $this->assertSame(1, $score['outcome_categories']['matched']);
        $this->assertSame(1, $score['facts']['matched']);
    }

    public function test_it_summarizes_scores(): void
    {
        $scorer = new LegalBenchmarkScorer();
        $scores = [
            [
                'passed' => true,
                'law' => ['matched' => 1, 'total' => 1],
                'articles' => ['matched' => 2, 'total' => 2],
                'cases' => ['matched' => 0, 'total' => 0],
                'echr' => ['matched' => 0, 'total' => 0],
                'rule_triggers' => ['matched' => 1, 'total' => 1],
                'outcome_categories' => ['matched' => 0, 'total' => 0],
                'outcomes' => ['matched' => 0, 'total' => 0],
                'facts' => ['matched' => 1, 'total' => 1],
                'forbidden_hits' => [],
            ],
            [
                'passed' => false,
                'law' => ['matched' => 0, 'total' => 1],
                'articles' => ['matched' => 1, 'total' => 2],
                'cases' => ['matched' => 0, 'total' => 0],
                'echr' => ['matched' => 0, 'total' => 0],
                'rule_triggers' => ['matched' => 0, 'total' => 1],
                'outcome_categories' => ['matched' => 0, 'total' => 0],
                'outcomes' => ['matched' => 0, 'total' => 0],
                'facts' => ['matched' => 0, 'total' => 1],
                'forbidden_hits' => ['law:29962'],
            ],
        ];

        $summary = $scorer->summarize($scores);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['passed']);
        $this->assertSame(0.5, $summary['law']['rate']);
        $this->assertSame(0.75, $summary['articles']['rate']);
        $this->assertSame(0.5, $summary['rule_triggers']['rate']);
        $this->assertSame(0.5, $summary['facts']['rate']);
        $this->assertSame(1, $summary['forbidden_hit_count']);
    }
}
