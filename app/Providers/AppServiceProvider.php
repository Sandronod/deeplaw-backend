<?php

namespace App\Providers;

use App\Contracts\AnswerServiceInterface;
use App\Contracts\EmbeddingServiceInterface;
use App\Services\AI\GeminiEmbeddingService;
use App\Services\AI\GeminiLegalAnswerService;
use App\Services\AI\OllamaEmbeddingService;
use App\Services\AI\OpenAIEmbeddingService;
use App\Services\AI\OpenAILegalAnswerService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;

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

    public function boot(): void
    {
        RateLimiter::for('chat-stream', function (Request $request) {
            $userLimit = max(1, (int) config('openai.chat_stream_rate_limit_per_minute', 6));
            $ipLimit = max($userLimit, (int) config('openai.chat_stream_ip_rate_limit_per_minute', 30));
            $userKey = $request->user()?->id
                ? 'user:' . $request->user()->id
                : 'guest:' . $request->ip();

            return [
                Limit::perMinute($userLimit)->by($userKey),
                Limit::perMinute($ipLimit)->by('ip:' . $request->ip()),
            ];
        });
    }
}
