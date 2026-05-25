<?php

return [
    'base_url'        => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'bge-m3'),
    'timeout'         => env('OLLAMA_TIMEOUT', 15),
    'force_cpu'       => env('OLLAMA_FORCE_CPU', false),
];
