<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    | Supported: "openai", "gemini"
    | Switch AI_PROVIDER in .env to change the active provider.
    | OpenAI uses vector search (3072-dim); Gemini uses keyword-only search.
    */
    'provider' => env('AI_PROVIDER', 'openai'),

    'gemini' => [
        'api_key'     => env('GEMINI_API_KEY', ''),
        'chat_model'  => env('GEMINI_CHAT_MODEL', 'gemini-1.5-flash'),
        'base_url'    => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout'     => env('GEMINI_TIMEOUT', 60),
        'max_tokens'  => env('GEMINI_MAX_TOKENS', 2048),
        'temperature' => env('GEMINI_TEMPERATURE', 0.2),
    ],
];
