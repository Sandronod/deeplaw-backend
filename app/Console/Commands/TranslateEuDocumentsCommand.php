<?php

namespace App\Console\Commands;

use App\Services\AI\OpenAIEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslateEuDocumentsCommand extends Command
{
    protected $signature = 'eu:translate
        {--batch=20    : Documents to translate per run}
        {--embed       : Also create embeddings after translation}
        {--model=gpt-4.1-mini : OpenAI model for translation}';

    protected $description = 'Translate eu_documents content to Georgian, optionally embed afterward';

    public function __construct(private OpenAIEmbeddingService $embedder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $batch = (int) $this->option('batch');
        $embed = $this->option('embed');
        $model = $this->option('model');

        // Get chunks that need translation (no translated_content yet)
        $rows = DB::connection('pgvector')
            ->table('eu_documents')
            ->whereNull('translated_content')
            ->limit($batch)
            ->get(['id', 'content', 'title']);

        if ($rows->isEmpty()) {
            $this->info('No untranslated documents found.');
            return 0;
        }

        $this->info("Translating {$rows->count()} chunks...");
        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        $done = 0;
        foreach ($rows as $row) {
            $translated = $this->translate($row->content, $model);
            if (!$translated) {
                $bar->advance();
                continue;
            }

            $update = ['translated_content' => $translated];

            if ($embed) {
                $embedding = $this->embedder->embed($translated);
                if ($embedding) {
                    $update['embedding'] = '[' . implode(',', $embedding) . ']';
                }
            }

            DB::connection('pgvector')
                ->table('eu_documents')
                ->where('id', $row->id)
                ->update($update);

            $done++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $remaining = DB::connection('pgvector')
            ->table('eu_documents')
            ->whereNull('translated_content')
            ->count();

        $this->info("Done. Translated: {$done}. Still remaining: {$remaining}");

        return 0;
    }

    private function translate(string $text, string $model): ?string
    {
        // Truncate very long chunks to keep API cost reasonable
        $text = mb_substr($text, 0, 3000);

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => $model,
                    'max_tokens'  => 2000,
                    'temperature' => 0,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => 'You are a legal translator. Translate the following EU legal text from English to Georgian (ქართული). Preserve legal terminology, article numbers, and structure exactly. Output only the Georgian translation, nothing else.',
                        ],
                        [
                            'role'    => 'user',
                            'content' => $text,
                        ],
                    ],
                ]);

            return $response->json('choices.0.message.content') ?? null;
        } catch (\Throwable $e) {
            Log::error('EU translate error', ['msg' => $e->getMessage()]);
            return null;
        }
    }
}
