<?php

return [
    'api_key'           => env('OPENAI_API_KEY'),
    'organization'      => env('OPENAI_ORGANIZATION'),
    'base_url'          => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'embedding_model'   => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
    'embedding_dimensions' => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 3072),
    'chat_model'        => env('OPENAI_CHAT_MODEL', 'gpt-4.1'),
    'dynamic_chat_model_enabled' => (bool) env('OPENAI_DYNAMIC_CHAT_MODEL_ENABLED', true),
    'complex_chat_model' => env('OPENAI_COMPLEX_CHAT_MODEL', 'gpt-4.1'),
    'complex_model_min_score' => (int) env('OPENAI_COMPLEX_MODEL_MIN_SCORE', 61),
    'complex_model_min_chars' => (int) env('OPENAI_COMPLEX_MODEL_MIN_CHARS', 700),
    // Lightweight model for keyword extraction (10x cheaper than chat_model)
    'extraction_model'  => env('OPENAI_EXTRACTION_MODEL', 'gpt-4.1-mini'),
    // High-reasoning judge model for LLM-as-Judge evaluation
    'judge_model'       => env('OPENAI_JUDGE_MODEL', 'o4-mini'),
    'judge_enabled'     => (bool) env('EVAL_JUDGE_ENABLED', false),
    'answer_correction_enabled' => (bool) env('ANSWER_CORRECTION_ENABLED', true),
    'chat_stream_rate_limit_per_minute' => (int) env('CHAT_STREAM_RATE_LIMIT_PER_MINUTE', 6),
    'chat_stream_ip_rate_limit_per_minute' => (int) env('CHAT_STREAM_IP_RATE_LIMIT_PER_MINUTE', 30),
    'timeout'           => (int) env('OPENAI_TIMEOUT', 60),
    'max_tokens'        => (int) env('OPENAI_MAX_TOKENS', 2200),
    'temperature'       => (float) env('OPENAI_TEMPERATURE', 0.2),

    // Per-answer OpenAI usage/cost estimate. Token counts come from API usage
    // metadata; prices are configurable because provider rates can change.
    'cost_tracking' => [
        'enabled' => (bool) env('OPENAI_COST_TRACKING_ENABLED', true),
        'currency' => env('OPENAI_COST_CURRENCY', 'USD'),
        'pricing_per_1m_tokens' => [
            'gpt-4.1' => [
                'input' => (float) env('OPENAI_PRICE_GPT_4_1_INPUT_PER_1M_USD', 2.00),
                'cached_input' => (float) env('OPENAI_PRICE_GPT_4_1_CACHED_INPUT_PER_1M_USD', 0.50),
                'output' => (float) env('OPENAI_PRICE_GPT_4_1_OUTPUT_PER_1M_USD', 8.00),
            ],
            'gpt-4.1-mini' => [
                'input' => (float) env('OPENAI_PRICE_GPT_4_1_MINI_INPUT_PER_1M_USD', 0.40),
                'cached_input' => (float) env('OPENAI_PRICE_GPT_4_1_MINI_CACHED_INPUT_PER_1M_USD', 0.10),
                'output' => (float) env('OPENAI_PRICE_GPT_4_1_MINI_OUTPUT_PER_1M_USD', 1.60),
            ],
            'gpt-4.1-nano' => [
                'input' => (float) env('OPENAI_PRICE_GPT_4_1_NANO_INPUT_PER_1M_USD', 0.10),
                'cached_input' => (float) env('OPENAI_PRICE_GPT_4_1_NANO_CACHED_INPUT_PER_1M_USD', 0.025),
                'output' => (float) env('OPENAI_PRICE_GPT_4_1_NANO_OUTPUT_PER_1M_USD', 0.40),
            ],
            'o4-mini' => [
                'input' => (float) env('OPENAI_PRICE_O4_MINI_INPUT_PER_1M_USD', 1.10),
                'cached_input' => (float) env('OPENAI_PRICE_O4_MINI_CACHED_INPUT_PER_1M_USD', 0.275),
                'output' => (float) env('OPENAI_PRICE_O4_MINI_OUTPUT_PER_1M_USD', 4.40),
            ],
            'text-embedding-3-large' => [
                'input' => (float) env('OPENAI_PRICE_TEXT_EMBEDDING_3_LARGE_INPUT_PER_1M_USD', 0.13),
                'cached_input' => 0.0,
                'output' => 0.0,
            ],
            'text-embedding-3-small' => [
                'input' => (float) env('OPENAI_PRICE_TEXT_EMBEDDING_3_SMALL_INPUT_PER_1M_USD', 0.02),
                'cached_input' => 0.0,
                'output' => 0.0,
            ],
            'default' => [
                'input' => (float) env('OPENAI_PRICE_DEFAULT_INPUT_PER_1M_USD', 0.0),
                'cached_input' => (float) env('OPENAI_PRICE_DEFAULT_CACHED_INPUT_PER_1M_USD', 0.0),
                'output' => (float) env('OPENAI_PRICE_DEFAULT_OUTPUT_PER_1M_USD', 0.0),
            ],
        ],
    ],

    // Text sent to OpenAI per decision (chars).
    'max_chars_per_decision'  => (int) env('MAX_CHARS_PER_DECISION', 7000),

    // Default chat sources. Comparative sources remain available from the UI.
    'default_sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('LEGAL_DEFAULT_SOURCES', 'court,matsne')),
    ))),

    // Context and visible citation budgets for ordinary answers.
    'max_context_decisions_default'  => (int) env('MAX_CONTEXT_DECISIONS_DEFAULT', 3),
    'max_context_decisions_complex'  => (int) env('MAX_CONTEXT_DECISIONS_COMPLEX', 5),
    'max_law_context_results'        => (int) env('MAX_LAW_CONTEXT_RESULTS', 4),
    'max_matsne_context_results'     => (int) env('MAX_MATSNE_CONTEXT_RESULTS', 4),
    'max_matsne_context_results_complex' => (int) env('MAX_MATSNE_CONTEXT_RESULTS_COMPLEX', 10),
    'max_echr_context_results'       => (int) env('MAX_ECHR_CONTEXT_RESULTS', 2),
    'max_eu_context_results'         => (int) env('MAX_EU_CONTEXT_RESULTS', 2),
    'max_german_context_results'     => (int) env('MAX_GERMAN_CONTEXT_RESULTS', 2),
    'max_const_court_context_results' => (int) env('MAX_CONST_COURT_CONTEXT_RESULTS', 2),
    'max_visible_sources_default'    => (int) env('MAX_VISIBLE_SOURCES_DEFAULT', 5),
    'max_visible_sources_complex'    => (int) env('MAX_VISIBLE_SOURCES_COMPLEX', 8),

    // Retrieval limits
    'retrieval_chunk_limit'   => (int) env('RETRIEVAL_CHUNK_LIMIT', 20),
    'retrieval_case_limit'    => (int) env('RETRIEVAL_CASE_LIMIT', 3),
    'answer_case_limit'       => (int) env('ANSWER_CASE_LIMIT', 5),
    'primary_case_limit'      => (int) env('PRIMARY_CASE_LIMIT', 2),
    'retrieval_min_score'     => (float) env('RETRIEVAL_MIN_SCORE', 0.65),
    'context_history_messages' => (int) env('CONTEXT_HISTORY_MESSAGES', 6),
];
