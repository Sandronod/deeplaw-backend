<?php

return [
    'api_key'           => env('OPENAI_API_KEY'),
    'organization'      => env('OPENAI_ORGANIZATION'),
    'base_url'          => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'embedding_model'   => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
    'embedding_dimensions' => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 3072),
    'chat_model'        => env('OPENAI_CHAT_MODEL', 'gpt-4.1'),
    'timeout'           => (int) env('OPENAI_TIMEOUT', 60),
    'max_tokens'        => (int) env('OPENAI_MAX_TOKENS', 2048),
    'temperature'       => (float) env('OPENAI_TEMPERATURE', 0.2),

    // Text sent to OpenAI per decision (chars). gpt-4.1 = 1M tokens, ასე რომ 40k char კარგია
    'max_chars_per_decision'  => (int) env('MAX_CHARS_PER_DECISION', 40000),

    // Retrieval limits
    'retrieval_chunk_limit'   => (int) env('RETRIEVAL_CHUNK_LIMIT', 20),
    'retrieval_case_limit'    => (int) env('RETRIEVAL_CASE_LIMIT', 3),
    'retrieval_min_score'     => (float) env('RETRIEVAL_MIN_SCORE', 0.65),
    'context_history_messages' => (int) env('CONTEXT_HISTORY_MESSAGES', 6),
];
