<?php

namespace Tests\Unit;

use App\Services\Legal\LegalCaseRetrieverService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LegalCaseRetrieverServiceTest extends TestCase
{
    public function test_case_embedding_match_gets_source_aware_score_boost(): void
    {
        $service = new LegalCaseRetrieverService();
        $method = new ReflectionMethod($service, 'computeCaseScores');

        /** @var Collection<int, array<string, mixed>> $scores */
        $scores = $method->invoke($service, collect([
            (object) [
                'case_id' => 1,
                'similarity' => 0.70,
                'match_source' => 'case_embedding',
            ],
            (object) [
                'case_id' => 2,
                'similarity' => 0.72,
                'match_source' => 'chunk_vector',
            ],
        ]));

        $byCase = $scores->keyBy('case_id');

        $this->assertGreaterThan($byCase[2]['score'], $byCase[1]['score']);
        $this->assertSame(0.70, $byCase[1]['case_sim']);
    }

    public function test_case_scores_preserve_match_sources(): void
    {
        $service = new LegalCaseRetrieverService();
        $method = new ReflectionMethod($service, 'computeCaseScores');

        /** @var Collection<int, array<string, mixed>> $scores */
        $scores = $method->invoke($service, collect([
            (object) [
                'case_id' => 189,
                'similarity' => 1.0,
                'match_source' => 'pasted_text',
            ],
            (object) [
                'case_id' => 189,
                'similarity' => 0.83,
                'match_source' => 'fingerprint',
            ],
            (object) [
                'case_id' => 917,
                'similarity' => 0.72,
                'match_source' => 'chunk_vector',
            ],
        ]));

        $byCase = $scores->keyBy('case_id');

        $this->assertSame(['pasted_text', 'fingerprint'], $byCase[189]['match_sources']);
        $this->assertSame(['chunk_vector'], $byCase[917]['match_sources']);
    }

    public function test_case_card_legal_issue_overlap_can_boost_relevance(): void
    {
        $service = new LegalCaseRetrieverService();
        $method = new ReflectionMethod($service, 'computeCaseScores');
        $query = 'საკასაციო საჩივრის დაუშვებლობა და სახელმწიფო ბაჟის გადახდის ვალდებულება';

        /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $scores */
        $scores = $method->invoke($service, collect([
            (object) [
                'case_id' => 1,
                'similarity' => 0.65,
                'match_source' => 'chunk_vector',
                'category' => 'საგადასახადო ურთიერთობებიდან წარმოშობილი დავები',
                'case_card' => json_encode([
                    'legal_issue' => $query,
                    'holding' => 'საკასაციო საჩივარი დაუშვებლად იქნა ცნობილი.',
                ], JSON_UNESCAPED_UNICODE),
            ],
            (object) [
                'case_id' => 2,
                'similarity' => 0.72,
                'match_source' => 'chunk_vector',
                'category' => 'სხვა დავა',
                'case_card' => json_encode([
                    'legal_issue' => 'ზიანის ანაზღაურების ვალდებულება სახელშეკრულებო დავაში',
                    'holding' => 'სარჩელი ნაწილობრივ დაკმაყოფილდა.',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]), $query);

        $byCase = $scores->keyBy('case_id');

        $this->assertGreaterThan($byCase[2]['score'], $byCase[1]['score']);
        $this->assertSame(1.0, $byCase[1]['case_card_score']);
    }

    public function test_case_card_keyword_match_gets_high_retrieval_score(): void
    {
        $service = new LegalCaseRetrieverService();
        $method = new ReflectionMethod($service, 'computeCaseScores');
        $query = 'საკასაციო საჩივრის დაუშვებლობის საკითხი და სახელმწიფო ბაჟის გადახდის ვალდებულება';

        /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $scores */
        $scores = $method->invoke($service, collect([
            (object) [
                'case_id' => 887,
                'similarity' => 0.99,
                'match_source' => 'case_card_keyword',
                'category' => 'საგადასახადო ურთიერთობებიდან წარმოშობილი დავები',
                'case_card' => json_encode([
                    'legal_issue' => $query,
                    'holding' => 'საკასაციო საჩივარი დაუშვებლად იქნა ცნობილი და დაეკისრა სახელმწიფო ბაჟის 70%-ის გადახდა.',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]), $query);

        $score = $scores->first();

        $this->assertGreaterThanOrEqual(0.96, $score['score']);
        $this->assertSame(['case_card_keyword'], $score['match_sources']);
        $this->assertSame(1.0, $score['case_card_score']);
    }
}
