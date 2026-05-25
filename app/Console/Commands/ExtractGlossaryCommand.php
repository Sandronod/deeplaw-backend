<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Extracts frequent Georgian legal terms from the corpus (cases + matsne_documents).
 *
 * Output: storage/app/glossary_raw.json
 *   [{ "term": "სარჩელი", "count": 48203, "sources": ["cases","matsne"] }, ...]
 *
 * Usage:
 *   php artisan glossary:extract
 *   php artisan glossary:extract --min-count=100   # higher threshold = fewer terms
 *   php artisan glossary:extract --limit=500000    # rows sampled from cases
 */
class ExtractGlossaryCommand extends Command
{
    protected $signature = 'glossary:extract
                            {--min-count=50  : Minimum frequency to include a term}
                            {--limit=300000  : Max rows to sample from cases table}
                            {--min-len=4     : Minimum character length of term}
                            {--max-len=30    : Maximum character length of term}';

    protected $description = 'Extract frequent Georgian legal terms from corpus into glossary_raw.json';

    // Georgian stop words — grammatical noise, not legal terms
    private const STOP_WORDS = [
        'რომ','და','ან','მაგრამ','თუ','რომელ','რადგან','ამიტომ','ასევე','თუმცა',
        'ეს','ის','ისინი','ჩვენ','თქვენ','მათ','მისი','მათი','ჩვენი','თქვენი',
        'არის','იყო','იქნება','იქნა','ხდება','ხდებოდა','ყოფილა','ყოფილი',
        'აქვს','ჰქონდა','ექნება','ჰქონდეს',
        'არ','არა','ვერ','ნუ','სხვა','ყველა','ყველ','ერთი','ერთ',
        'შემდეგ','წინ','მერე','კვლავ','ისევ','უკვე','კიდევ','მხოლოდ',
        'ასეთ','ასეთი','ამდენი','იმდენი','ამგვარ','ამგვარი',
        'ასე','ისე','თითქოს','ვითარცა','ვითომ',
        'ძალიან','ჯერ','კი','ხომ','კია','ბოლოს','პირველ','პირველი',
        'ერთად','ასევე','მაშინ','მაშასადამე','ამასთან','გარდა',
        'ვინც','რომელც','რაც','სადაც','სადაც','სადაც',
        'მათ','მათი','ამათ','იმათ','ამის','იმის','ამას','იმას',
        'შესახებ','მიხედვით','საფუძველზე','შესაბამისად','მიმართ',
        'წლის','წელი','წელს','წელი','თვის','თვეს','დღის','დღეს','დღე',
        'პირველი','მეორე','მესამე','მეოთხე','მეხუთე',
        'ნომერი','ნომრი','ნომ','ნომ',
    ];

    // Suffixes to strip for basic stemming (longest first)
    private const SUFFIXES = [
        'ებისათვის','ებისაგან','ებისათვ','ებამდე','ებიდან',
        'ებისთვის','ებისგან','ებზეა','ობებ','ებულ',
        'ებისა','ებთან','ებიდ','ებზე','ებში','ებად','ებით','ებს','ება','ებ',
        'ისათვის','ისაგან','ისთვის','ისგან','იდან','ამდე',
        'ისა','თთან','ებოდ','ობდ','ულობ',
        'ისთვ','ებოდ','ობდ',
        'ადმი','ამდე','ასთვ',
        'ისთ','ოდნ','ადნ',
        'ული','ური','ებრ',
        'ობა','ება','ება',
        'ისა','ითა',
        'ამა','იმა',
        'თან','ზეა','შია',
        'ის','ს','მა','ად','ით','ზე','ში','ვე','თა','ნი',
        'ა','ო','ე','ი',
    ];

    public function handle(): int
    {
        $minCount = (int) $this->option('min-count');
        $limit    = (int) $this->option('limit');
        $minLen   = (int) $this->option('min-len');
        $maxLen   = (int) $this->option('max-len');

        $this->info("Extracting Georgian legal terms from corpus...");
        $this->info("Cases limit: {$limit} | Min frequency: {$minCount} | Min length: {$minLen}");

        $freq    = [];   // root → ['count' => N, 'best_form' => str, 'sources' => []]
        $stop    = array_flip(self::STOP_WORDS);

        // ── 1. Process cases ──────────────────────────────────────────────────
        $this->info('Reading cases...');
        $bar = $this->output->createProgressBar(min($limit, 780000));

        DB::connection('pgvector')
            ->table('cases')
            ->select('content')
            ->whereNotNull('content')
            ->orderBy('id')
            ->limit($limit)
            ->chunk(5000, function ($rows) use (&$freq, $stop, $minLen, $maxLen, $bar) {
                foreach ($rows as $row) {
                    $this->processText((string) $row->content, 'cases', $freq, $stop, $minLen, $maxLen);
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();

        // ── 2. Process matsne_documents ───────────────────────────────────────
        $this->info('Reading matsne_documents...');
        $total = DB::connection('pgvector')->table('matsne_documents')->whereNotNull('content')->count();
        $bar2  = $this->output->createProgressBar($total);

        DB::connection('pgvector')
            ->table('matsne_documents')
            ->select('content')
            ->whereNotNull('content')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$freq, $stop, $minLen, $maxLen, $bar2) {
                foreach ($rows as $row) {
                    $this->processText((string) $row->content, 'matsne', $freq, $stop, $minLen, $maxLen);
                    $bar2->advance();
                }
            });

        $bar2->finish();
        $this->newLine();

        // ── 3. Filter by min count ────────────────────────────────────────────
        $filtered = array_filter($freq, fn($v) => $v['count'] >= $minCount);
        arsort($filtered);  // sort by count desc... actually sort by count

        uasort($filtered, fn($a, $b) => $b['count'] <=> $a['count']);

        // ── 4. Build output ───────────────────────────────────────────────────
        $output = [];
        foreach ($filtered as $root => $data) {
            $output[] = [
                'term'       => $data['best_form'],
                'root'       => $root,
                'count'      => $data['count'],
                'sources'    => array_values(array_filter(['cases', 'matsne'], fn($s) => $data['src_' . $s] ?? false)),
                'domain'     => null,
                'ka_definition' => null,
                'en_note'    => null,
                'synonyms_ka'=> [],
                'reviewed'   => false,
            ];
        }

        $path = storage_path('app/glossary_raw.json');
        file_put_contents($path, json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info(sprintf(
            "\nDone. %d unique terms found (min frequency %d). Saved to: %s",
            count($output),
            $minCount,
            $path
        ));

        // Show top 30 as preview
        $this->table(['Term', 'Count', 'Sources'], array_map(
            fn($r) => [$r['term'], $r['count'], implode(',', $r['sources'])],
            array_slice($output, 0, 30)
        ));

        return self::SUCCESS;
    }

    private function processText(string $text, string $source, array &$freq, array $stop, int $minLen, int $maxLen): void
    {
        // Extract Georgian words only (Unicode range U+10D0–U+10FF)
        preg_match_all('/[\x{10D0}-\x{10FF}]{' . $minLen . ',' . $maxLen . '}/u', $text, $matches);

        foreach ($matches[0] as $word) {
            $lower = mb_strtolower($word);

            if (isset($stop[$lower])) {
                continue;
            }

            $root = $this->stem($lower);

            if (mb_strlen($root) < $minLen) {
                continue;
            }

            if (!isset($freq[$root])) {
                $freq[$root] = ['count' => 0, 'best_form' => $word, 'src_cases' => false, 'src_matsne' => false];
            }

            $freq[$root]['count']++;
            $freq[$root]['src_' . $source] = true;

            // Keep the shortest form as canonical (likely nominative)
            if (mb_strlen($word) < mb_strlen($freq[$root]['best_form'])) {
                $freq[$root]['best_form'] = $word;
            }
        }
    }

    /**
     * Strip Georgian inflectional suffixes to get approximate root form.
     * Not a full morphological analyzer — just reduces noise in frequency counts.
     */
    private function stem(string $word): string
    {
        foreach (self::SUFFIXES as $suffix) {
            $sLen = mb_strlen($suffix);
            $wLen = mb_strlen($word);

            if ($wLen - $sLen >= 3 && mb_substr($word, -$sLen) === $suffix) {
                return mb_substr($word, 0, $wLen - $sLen);
            }
        }

        return $word;
    }
}
