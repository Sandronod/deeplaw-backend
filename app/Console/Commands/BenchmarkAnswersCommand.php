<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Services\Legal\LegalChatOrchestratorService;
use Illuminate\Console\Command;

class BenchmarkAnswersCommand extends Command
{
    protected $signature = 'benchmark:answers
                            {--norm-file= : Norm benchmark JSON file}
                            {--court-file= : Court gold-set JSON file}
                            {--norms=20 : Number of norm questions}
                            {--courts=10 : Number of court-practice questions}
                            {--query-style=gold : Query style: gold, large, or full}
                            {--json= : Write JSON report path}
                            {--details : Show per-question table}
                            {--keep-chats : Keep temporary benchmark chats}';

    protected $description = 'Run live answer-generation benchmark and validate answer grounding';

    public function __construct(
        private readonly LegalChatOrchestratorService $orchestrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $normFile = $this->resolvePath($this->option('norm-file') ?: 'tests/Fixtures/legal_core_benchmark.json');
        $courtFile = $this->resolvePath($this->option('court-file') ?: storage_path('app/eval/gold_set.json'));
        $queryStyle = $this->queryStyle((string) $this->option('query-style'));

        $scenarios = array_merge(
            $this->loadNormScenarios($normFile, max(0, (int) $this->option('norms')), $queryStyle),
            $this->loadCourtScenarios($courtFile, max(0, (int) $this->option('courts')), $queryStyle),
        );

        if (empty($scenarios)) {
            $this->error('No answer scenarios found.');
            return self::FAILURE;
        }

        $this->info('Answer benchmark');
        $this->line('Questions: ' . count($scenarios));
        $this->line('Query style: ' . $queryStyle);
        $this->newLine();

        $results = [];
        $rows = [];

        $bar = $this->output->createProgressBar(count($scenarios));
        $bar->start();

        foreach ($scenarios as $scenario) {
            $result = $this->runScenario($scenario);
            $results[] = $result;
            $rows[] = [
                $scenario['id'],
                $scenario['kind'],
                $result['status'],
                $result['score'],
                $result['retrieval_pass'] ? 'yes' : 'no',
                $result['answer_validation']['verdict'] ?? 'n/a',
                $result['citation_flagged'] ? 'yes' : 'no',
                $result['expected_reference_in_answer'] ? 'yes' : 'no',
                (string) $result['query_chars'],
                $result['query_complexity']['level'] ?? '',
                $result['error'] ? mb_substr($result['error'], 0, 60) : '',
            ];
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $summary = $this->summarize($results);
        $this->printSummary($summary);

        if ($this->option('details')) {
            $this->newLine();
            $this->table(
                ['ID', 'Kind', 'Status', 'Score', 'Retrieval', 'Validation', 'Citation Flag', 'Expected Ref', 'Chars', 'Complexity', 'Error'],
                $rows,
            );
        }

        $jsonPath = $this->option('json')
            ? $this->resolvePath($this->option('json'))
            : storage_path('app/benchmarks/answer_quality_report.json');

        $this->writeJsonReport($jsonPath, [
            'generated_at' => now()->toISOString(),
            'query_style' => $queryStyle,
            'summary' => $summary,
            'results' => $results,
        ]);

        $this->info("JSON report written: {$jsonPath}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadNormScenarios(string $path, int $limit, string $queryStyle): array
    {
        if ($limit === 0 || !file_exists($path)) {
            return [];
        }

        $payload = json_decode(file_get_contents($path), true);
        $items = array_slice($payload['scenarios'] ?? [], 0, $limit);

        return array_map(fn (array $scenario) => [
            'id' => $this->styledId((string) $scenario['id'], $queryStyle),
            'kind' => 'norm',
            'query' => $this->buildStyledQuery((string) $scenario['query'], 'norm', $queryStyle),
            'query_style' => $queryStyle,
            'sources' => $scenario['sources'] ?? ['matsne'],
            'expected' => $scenario['expected'] ?? [],
        ], $items);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCourtScenarios(string $path, int $limit, string $queryStyle): array
    {
        if ($limit === 0 || !file_exists($path)) {
            return [];
        }

        $items = array_slice(json_decode(file_get_contents($path), true) ?: [], 0, $limit);

        return array_map(fn (array $scenario) => [
            'id' => $this->styledId('court_' . $scenario['id'], $queryStyle),
            'kind' => 'court',
            'query' => $this->buildStyledQuery((string) $scenario['query'], 'court', $queryStyle, $scenario),
            'query_style' => $queryStyle,
            'sources' => ['court'],
            'expected' => [
                'case_id' => $scenario['expected_case_id'] ?? null,
                'case_num' => $scenario['case_num'] ?? null,
                'holding' => $scenario['holding'] ?? null,
            ],
        ], $items);
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    private function runScenario(array $scenario): array
    {
        $chat = null;

        try {
            $chat = Chat::create(['title' => '[answer-benchmark] ' . $scenario['id']]);
            $response = $this->orchestrator->handle($chat, $scenario['query'], $scenario['sources']);
            $message = $response['message'];
            $meta = $message->meta ?? [];
            $answer = (string) $message->content;

            $retrievalPass = $this->retrievalPass($scenario, $meta);
            $expectedReferenceInAnswer = $this->expectedReferenceInAnswer($scenario, $answer);
            $citationFlagged = (bool) ($meta['citation_check']['flagged'] ?? false);
            $validation = $meta['answer_validation'] ?? ['verdict' => 'missing', 'score' => 0, 'flags' => []];
            $score = $this->score($retrievalPass, $expectedReferenceInAnswer, $citationFlagged, $validation);

            return [
                'id' => $scenario['id'],
                'kind' => $scenario['kind'],
                'query' => $scenario['query'],
                'query_style' => $scenario['query_style'] ?? 'gold',
                'query_chars' => mb_strlen($scenario['query']),
                'expected' => $scenario['expected'] ?? [],
                'status' => $this->status($score, $validation),
                'score' => $score,
                'retrieval_pass' => $retrievalPass,
                'expected_reference_in_answer' => $expectedReferenceInAnswer,
                'citation_flagged' => $citationFlagged,
                'answer_validation' => $validation,
                'citations' => [
                    'domestic' => $meta['citations'] ?? [],
                    'matsne' => $meta['matsne_citations'] ?? [],
                    'echr' => $meta['echr_citations'] ?? [],
                ],
                'case_relevance_ranking' => $meta['case_relevance_ranking'] ?? [],
                'pipeline_ms' => $meta['pipeline_ms'] ?? null,
                'timings_ms' => $meta['timings_ms'] ?? [],
                'query_complexity' => $meta['query_complexity'] ?? [],
                'debug' => $meta['debug'] ?? [],
                'answer_excerpt' => mb_substr($answer, 0, 1200),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'id' => $scenario['id'],
                'kind' => $scenario['kind'],
                'query' => $scenario['query'],
                'query_style' => $scenario['query_style'] ?? 'gold',
                'query_chars' => mb_strlen($scenario['query']),
                'expected' => $scenario['expected'] ?? [],
                'status' => 'error',
                'score' => 0,
                'retrieval_pass' => false,
                'expected_reference_in_answer' => false,
                'citation_flagged' => false,
                'answer_validation' => ['verdict' => 'error', 'score' => 0, 'flags' => []],
                'citations' => [],
                'answer_excerpt' => '',
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($chat !== null && !$this->option('keep-chats')) {
                $chat->messages()->delete();
                $chat->delete();
            }
        }
    }

    /**
     * @param array<string, mixed> $scenario
     * @param array<string, mixed> $meta
     */
    private function retrievalPass(array $scenario, array $meta): bool
    {
        if ($scenario['kind'] === 'norm') {
            return $this->normRetrievalPass($scenario['expected']['matsne'] ?? [], $meta['matsne_citations'] ?? []);
        }

        $expectedCaseId = (int) ($scenario['expected']['case_id'] ?? 0);
        if ($expectedCaseId === 0) {
            return false;
        }

        $caseIds = array_map(fn (array $citation) => (int) ($citation['case_id'] ?? 0), $meta['citations'] ?? []);

        return in_array($expectedCaseId, $caseIds, true);
    }

    /**
     * @param array<int, array<string, mixed>> $expected
     * @param array<int, array<string, mixed>> $actual
     */
    private function normRetrievalPass(array $expected, array $actual): bool
    {
        foreach ($expected as $expectedLaw) {
            $expectedLawId = (int) ($expectedLaw['matsne_id'] ?? 0);
            $expectedArticles = array_map('intval', $expectedLaw['articles'] ?? []);

            foreach ($expectedArticles as $article) {
                $found = false;
                foreach ($actual as $citation) {
                    if (
                        (int) ($citation['matsne_id'] ?? 0) === $expectedLawId
                        && (int) ($citation['article_num'] ?? 0) === $article
                    ) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    return false;
                }
            }
        }

        return !empty($expected);
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function expectedReferenceInAnswer(array $scenario, string $answer): bool
    {
        if ($scenario['kind'] === 'court') {
            $caseNum = (string) ($scenario['expected']['case_num'] ?? '');
            return $caseNum !== '' && str_contains($this->normalize($answer), $this->normalize($caseNum));
        }

        foreach ($scenario['expected']['matsne'] ?? [] as $expectedLaw) {
            foreach (($expectedLaw['articles'] ?? []) as $article) {
                if (!$this->answerMentionsArticle($answer, (int) $article)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function answerMentionsArticle(string $answer, int $article): bool
    {
        $article = preg_quote((string) $article, '/');

        return (bool) (
            preg_match("/მუხლ(?:ი|ის|ით|ზე|ში|იდან|ად|ებს|ები)?\s*№?\s*{$article}(?:\D|$)/u", $answer)
            || preg_match("/(?:^|\D){$article}(?:-?ე|ე)?\s+მუხლ/u", $answer)
        );
    }

    /**
     * @param array<string, mixed> $validation
     */
    private function score(
        bool $retrievalPass,
        bool $expectedReferenceInAnswer,
        bool $citationFlagged,
        array $validation,
    ): int {
        $score = 0;
        $score += $retrievalPass ? 50 : 0;
        $score += match ($validation['verdict'] ?? 'fail') {
            'pass' => 30,
            'warn' => 15,
            default => 0,
        };
        $score += $citationFlagged ? 0 : 10;
        $score += $expectedReferenceInAnswer ? 10 : 0;

        return $score;
    }

    /**
     * @param array<string, mixed> $validation
     */
    private function status(int $score, array $validation): string
    {
        if (($validation['verdict'] ?? null) === 'fail') {
            return 'fail';
        }

        return match (true) {
            $score >= 85 => 'pass',
            $score >= 65 => 'warn',
            default => 'fail',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function summarize(array $results): array
    {
        $total = count($results);
        $passed = count(array_filter($results, fn (array $r) => $r['status'] === 'pass'));
        $warned = count(array_filter($results, fn (array $r) => $r['status'] === 'warn'));
        $failed = count(array_filter($results, fn (array $r) => $r['status'] === 'fail'));
        $errors = count(array_filter($results, fn (array $r) => $r['status'] === 'error'));
        $retrievalHits = count(array_filter($results, fn (array $r) => $r['retrieval_pass']));
        $referenceHits = count(array_filter($results, fn (array $r) => $r['expected_reference_in_answer']));
        $citationFlags = count(array_filter($results, fn (array $r) => $r['citation_flagged']));
        $validationPass = count(array_filter($results, fn (array $r) => ($r['answer_validation']['verdict'] ?? null) === 'pass'));
        $validationWarn = count(array_filter($results, fn (array $r) => ($r['answer_validation']['verdict'] ?? null) === 'warn'));
        $validationFail = count(array_filter($results, fn (array $r) => ($r['answer_validation']['verdict'] ?? null) === 'fail'));

        return [
            'total' => $total,
            'passed' => $passed,
            'warned' => $warned,
            'failed' => $failed,
            'errors' => $errors,
            'pass_rate' => $total > 0 ? round($passed / $total, 4) : 0.0,
            'average_score' => $total > 0 ? round(array_sum(array_column($results, 'score')) / $total, 2) : 0.0,
            'retrieval_hit_rate' => $total > 0 ? round($retrievalHits / $total, 4) : 0.0,
            'expected_reference_rate' => $total > 0 ? round($referenceHits / $total, 4) : 0.0,
            'citation_flag_count' => $citationFlags,
            'answer_validation' => [
                'pass' => $validationPass,
                'warn' => $validationWarn,
                'fail' => $validationFail,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function printSummary(array $summary): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total', (string) $summary['total']],
                ['Pass / Warn / Fail / Error', "{$summary['passed']} / {$summary['warned']} / {$summary['failed']} / {$summary['errors']}"],
                ['Pass rate', number_format($summary['pass_rate'] * 100, 1) . '%'],
                ['Average score', (string) $summary['average_score']],
                ['Retrieval hit rate', number_format($summary['retrieval_hit_rate'] * 100, 1) . '%'],
                ['Expected ref in answer', number_format($summary['expected_reference_rate'] * 100, 1) . '%'],
                ['Citation flags', (string) $summary['citation_flag_count']],
                ['Answer validation pass/warn/fail', implode(' / ', $summary['answer_validation'])],
            ],
        );
    }

    private function queryStyle(string $style): string
    {
        $style = mb_strtolower(trim($style));

        return in_array($style, ['gold', 'large', 'full'], true) ? $style : 'gold';
    }

    private function styledId(string $id, string $queryStyle): string
    {
        return $queryStyle === 'gold' ? $id : "{$id}_{$queryStyle}";
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function buildStyledQuery(string $baseQuery, string $kind, string $queryStyle, array $scenario = []): string
    {
        $baseQuery = trim($baseQuery);

        return match ($queryStyle) {
            'large' => $this->buildLargeQuery($baseQuery, $kind, $scenario),
            'full' => $this->buildFullQuery($baseQuery, $kind, $scenario),
            default => $baseQuery,
        };
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function buildLargeQuery(string $baseQuery, string $kind, array $scenario): string
    {
        $sourceTask = $kind === 'court'
            ? 'მოძებნე უზენაესი სასამართლოს პრაქტიკა და გამოყავი ყველაზე რელევანტური 1-2 გადაწყვეტილება.'
            : 'მოძებნე მოქმედი ნორმები, სწორი კანონი და კონკრეტული მუხლები.';

        $category = trim((string) ($scenario['category'] ?? ''));
        $categoryLine = $category !== '' ? "\nდავის სავარაუდო კატეგორია: {$category}" : '';

        return trim(<<<TEXT
კაზუსი:
კლიენტმა მომმართა სამართლებრივი შეფასებისთვის. მას სჭირდება არა ზოგადი საუბარი, არამედ წყაროებზე დაფუძნებული პასუხი.
ძირითადი სამართლებრივი საკითხი ასეთია: {$baseQuery}{$categoryLine}

ფაქტობრივი ფონი:
პირველ ეტაპზე ერთმა მხარემ მიიღო მისთვის არასასურველი შედეგი და აპირებს დავის გაგრძელებას. მეორე მხარე ამტკიცებს, რომ მოთხოვნა ან საჩივარი დაუშვებელია, ან არ არსებობს მისი დაკმაყოფილების საკმარისი სამართლებრივი საფუძველი. საქმეში მნიშვნელოვანია როგორც პროცედურული ნაწილი, ისე მატერიალური დასაბუთება.

დავალება:
1. გამოარჩიე მთავარი სამართლებრივი საკითხი.
2. {$sourceTask}
3. პასუხში მიუთითე, რატომ არის მოძებნილი წყაროები რელევანტური.
4. საბოლოოდ ჩამოაყალიბე მოკლე დასკვნა, რა პოზიცია ჩანს უფრო დასაბუთებული.
TEXT);
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function buildFullQuery(string $baseQuery, string $kind, array $scenario): string
    {
        $sourceTask = $kind === 'court'
            ? 'უზენაესი სასამართლოს პრაქტიკიდან შეარჩიე ყველაზე ახლო საქმეები და არ შემოიფარგლო მხოლოდ სიტყვიერი დამთხვევით.'
            : 'ნორმებიდან შეარჩიე ზუსტად ის კანონი და მუხლი, რომელიც ამ ფაქტობრივ სურათს პასუხობს.';

        $category = trim((string) ($scenario['category'] ?? ''));
        $categoryLine = $category !== '' ? "\nდავის მიმართულება/კატეგორია: {$category}" : '';

        return trim(<<<TEXT
სრული კაზუსი:
იურიდიულ კონსულტაციაზე მოვიდა კლიენტი, რომელსაც სურს გაიგოს, რა სამართლებრივი პოზიცია შეიძლება ჰქონდეს დავაში. კლიენტის მონათხრობის მიხედვით, დავა რამდენიმე ეტაპს მოიცავს: ადმინისტრაციული ან სასამართლო წარმოება უკვე გაიმართა, ერთმა მხარემ შედეგი გაასაჩივრა, ხოლო მეორე მხარე ამტკიცებს, რომ გასაჩივრება ან მოთხოვნა სამართლებრივად არ უნდა დაკმაყოფილდეს.

საქმის არსი:
{$baseQuery}{$categoryLine}

დამატებითი გარემოებები:
კლიენტი ამბობს, რომ ქვედა ინსტანციამ ფაქტები ნაწილობრივ სწორად დაადგინა, მაგრამ სამართლებრივი შეფასება სადავოა. მეორე მხარე მიუთითებს პროცესუალურ შეზღუდვებზე, დასაშვებობის კრიტერიუმებზე, მტკიცების ტვირთზე და იმაზე, რომ მსგავსი დავები სასამართლო პრაქტიკაში უკვე შეფასებულია. კლიენტს აინტერესებს არა მხოლოდ საბოლოო პასუხი, არამედ ისიც, რომელი წყარო აძლევს ამ პასუხს რეალურ საყრდენს.

დამატებითი ხმაური, რომელიც შეიძლება არსებითი არ იყოს:
მხარეებს შორის არსებობდა კომუნიკაცია, შედგა რამდენიმე წერილი, ერთმა მხარემ მიუთითა ფინანსურ ზიანზე, მეორე მხარემ კი პროცედურულ ხარვეზებზე. ამავე დროს, დავის გადაწყვეტისთვის შეიძლება გადამწყვეტი იყოს არა ყველა ეს ფაქტი, არამედ ის სამართლებრივი სტანდარტი, რომელსაც შესაბამისი ნორმა ან სასამართლო პრაქტიკა აყალიბებს.

მინდა პასუხი ასეთ ფორმატში:
1. მთავარი სამართლებრივი საკითხის იდენტიფიკაცია.
2. {$sourceTask}
3. თუ რამდენიმე წყარო მოიძებნა, ერთმანეთისგან გამიჯნე ყველაზე რელევანტური და მხოლოდ დამხმარე წყაროები.
4. წყაროებზე დაყრდნობით მომეცი დასკვნა.
5. თუ პასუხი სასამართლო პრაქტიკას ეყრდნობა, მიუთითე გადაწყვეტილების ნომერი; თუ ნორმას ეყრდნობა, მიუთითე კანონი და მუხლი.
TEXT);
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeJsonReport(string $path, array $report): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', '', $text)));
    }
}
