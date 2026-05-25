<?php

namespace App\Console\Commands;

use App\Models\EuDocument;
use App\Contracts\EmbeddingServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchEuCasesCommand extends Command
{
    protected $signature = 'eu:fetch-cases
        {--court=cjeu      : Court filter (cjeu|general|both)}
        {--limit=100       : Max cases to fetch}
        {--year=           : Filter by year (e.g. 2023)}
        {--chunk=800       : Chunk size in characters}
        {--overlap=100     : Chunk overlap in characters}
        {--dry-run         : Fetch metadata only, no embedding}';

    protected $description = 'Fetch CJEU/General Court judgments from CELLAR and embed into eu_documents';

    private const SPARQL_URL  = 'https://publications.europa.eu/webapi/rdf/sparql';
    private const CELLAR_BASE = 'https://publications.europa.eu/resource/cellar/';

    // CDM court resource URIs
    private const COURTS = [
        'cjeu'    => 'http://publications.europa.eu/resource/authority/corporate-body/CJEU',
        'general' => 'http://publications.europa.eu/resource/authority/corporate-body/GCEU',
    ];

    public function __construct(private EmbeddingServiceInterface $embedder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $courtOpt = $this->option('court');
        $limit    = (int) $this->option('limit');
        $year     = $this->option('year');
        $chunk    = (int) $this->option('chunk');
        $overlap  = (int) $this->option('overlap');
        $dryRun   = $this->option('dry-run');

        $yearFilter = $year
            ? "FILTER(?date >= \"{$year}-01-01\"^^xsd:date && ?date <= \"{$year}-12-31\"^^xsd:date)"
            : '';

        $courtFilter = '';
        if ($courtOpt !== 'both') {
            $courtUri    = self::COURTS[$courtOpt] ?? self::COURTS['cjeu'];
            $courtFilter = "?work cdm:work_created_by_agent <{$courtUri}> .";
        }

        $sparql = <<<SPARQL
        PREFIX cdm: <http://publications.europa.eu/ontology/cdm#>
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
        SELECT DISTINCT ?work ?title ?date ?caseNum WHERE {
          ?work a cdm:judgement ;
                cdm:work_date_document ?date .
          OPTIONAL { ?work cdm:work_title ?title . FILTER(LANG(?title) = "en") }
          OPTIONAL { ?work cdm:case_law_case_number ?caseNum }
          {$courtFilter}
          {$yearFilter}
        }
        ORDER BY DESC(?date)
        LIMIT {$limit}
        SPARQL;

        $this->info("Querying CELLAR for CJEU judgments (court: {$courtOpt})...");

        $results = $this->sparqlQuery($sparql);
        if ($results === null) {
            $this->error('SPARQL query failed.');
            return 1;
        }

        $bindings = $results['results']['bindings'] ?? [];
        $this->info('Found ' . count($bindings) . ' cases.');

        $bar = $this->output->createProgressBar(count($bindings));
        $bar->start();

        $stored = 0;
        foreach ($bindings as $row) {
            $cellarUri = $row['work']['value'];
            $cellarId  = basename($cellarUri);
            $title     = $row['title']['value'] ?? null;
            $date      = $row['date']['value'] ?? null;
            $caseNum   = $row['caseNum']['value'] ?? null;

            $courtLabel = match ($courtOpt) {
                'general' => 'General Court',
                'both'    => 'EU Court',
                default   => 'Court of Justice',
            };

            if (EuDocument::where('cellar_id', $cellarId)->exists()) {
                $bar->advance();
                continue;
            }

            $content = $this->fetchContent($cellarUri);
            if (empty(trim($content))) {
                $bar->advance();
                continue;
            }

            $chunks = $this->chunkText($content, $chunk, $overlap);

            foreach ($chunks as $i => $chunkText) {
                $embedding = $dryRun ? null : $this->embedder->embed($chunkText);

                EuDocument::create([
                    'cellar_id'    => $cellarId,
                    'doc_type'     => 'judgment',
                    'source'       => 'case_law',
                    'court'        => $courtLabel,
                    'case_num'     => $caseNum,
                    'title'        => $title,
                    'doc_date'     => $date ? date('Y-m-d', strtotime($date)) : null,
                    'language'     => 'en',
                    'content'      => $chunkText,
                    'embedding'    => $embedding ? '[' . implode(',', $embedding) . ']' : null,
                    'content_hash' => hash('sha256', $chunkText),
                    'meta'         => [
                        'chunk_index'  => $i,
                        'total_chunks' => count($chunks),
                        'cellar_uri'   => $cellarUri,
                        'url'          => "https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=cellar:{$cellarId}",
                    ],
                ]);
            }

            $stored++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Stored {$stored} new CJEU cases.");

        return 0;
    }

    private function sparqlQuery(string $query): ?array
    {
        try {
            $response = Http::timeout(60)
                ->withHeaders(['Accept' => 'application/sparql-results+json'])
                ->asForm()
                ->post(self::SPARQL_URL, ['query' => $query]);

            if (!$response->ok()) {
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('CELLAR SPARQL exception', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchContent(string $cellarUri): string
    {
        $cellarId = basename($cellarUri);

        // 1. Try CELLAR REST API (HTML then plain text)
        foreach (['application/xhtml+xml,text/html', 'text/plain'] as $accept) {
            try {
                $response = Http::timeout(20)
                    ->withHeaders([
                        'Accept'          => $accept,
                        'Accept-Language' => 'en',
                    ])
                    ->get(self::CELLAR_BASE . $cellarId);

                if ($response->ok()) {
                    $body = $response->body();
                    if (str_contains($accept, 'html')) {
                        $body = strip_tags($body);
                    }
                    $body = preg_replace('/\s+/', ' ', $body);
                    if (strlen(trim($body)) > 200) {
                        return trim($body);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // 2. Fallback: EUR-Lex HTML page (works for older docs where CELLAR returns PDF)
        try {
            $eurLexUrl = "https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=cellar:{$cellarId}";
            $response  = Http::timeout(30)
                ->withHeaders([
                    'Accept'          => 'text/html',
                    'Accept-Language' => 'en',
                    'User-Agent'      => 'Mozilla/5.0 (compatible; LegalBot/1.0)',
                ])
                ->get($eurLexUrl);

            if ($response->ok()) {
                $body = strip_tags($response->body());
                $body = preg_replace('/\s+/', ' ', $body);
                if (strlen(trim($body)) > 200) {
                    return trim($body);
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return '';
    }

    private function chunkText(string $text, int $size, int $overlap): array
    {
        $chunks = [];
        $len    = mb_strlen($text);
        $start  = 0;

        while ($start < $len) {
            $chunk = mb_substr($text, $start, $size);
            if (mb_strlen(trim($chunk)) > 50) {
                $chunks[] = trim($chunk);
            }
            $start += $size - $overlap;
        }

        return $chunks ?: [mb_substr($text, 0, $size)];
    }
}
