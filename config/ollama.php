<?php

return [
    'host' => env('OLLAMA_HOST', 'http://127.0.0.1:11434'),
    'api_key' => env('OLLAMA_API_KEY'),
    'headers' => json_decode((string) env('OLLAMA_HEADERS', '[]'), true) ?: [],
    'agent_model' => env('OLLAMA_AGENT_MODEL', 'glm-5:cloud'),
    'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'qwen3-embedding:0.6b'),
    'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
    'context_length' => (int) env('OLLAMA_CONTEXT_LENGTH', 8192),
];
