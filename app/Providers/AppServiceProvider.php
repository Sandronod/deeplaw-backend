<?php

namespace App\Providers;

use App\Contracts\AnswerServiceInterface;
use App\Contracts\EmbeddingServiceInterface;
use App\Services\AI\GeminiEmbeddingService;
use App\Services\AI\GeminiLegalAnswerService;
use App\Services\AI\OllamaEmbeddingService;
use App\Services\AI\OpenAIEmbeddingService;
use App\Services\AI\OpenAILegalAnswerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $provider = config('ai.provider', 'openai');

        if ($provider === 'gemini') {
            $this->app->bind(EmbeddingServiceInterface::class, GeminiEmbeddingService::class);
            $this->app->bind(AnswerServiceInterface::class, GeminiLegalAnswerService::class);
        } elseif ($provider === 'ollama') {
            $this->app->bind(EmbeddingServiceInterface::class, OllamaEmbeddingService::class);
            $this->app->bind(AnswerServiceInterface::class, OpenAILegalAnswerService::class);
        } else {
            $this->app->bind(EmbeddingServiceInterface::class, OpenAIEmbeddingService::class);
            $this->app->bind(AnswerServiceInterface::class, OpenAILegalAnswerService::class);
        }
    }

    public function boot(): void {}
}
