<?php

declare(strict_types=1);

return [
    /*
     |--------------------------------------------------------------------------
     | Ollama Configuration
     |--------------------------------------------------------------------------
     |
     | Configure which model to use for semantic categorisation. The tool will
     | fall back to keyword-based categorisation if Ollama is unavailable.
     |
     */
    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'deepseek-r1'),
        'timeout' => (int)env('OLLAMA_TIMEOUT', 120),
        'temperature' => 0.1,
        'max_tokens' => 4096,
    ],

    /*
     |--------------------------------------------------------------------------
     | Categories File
     |--------------------------------------------------------------------------
     |
     | Path to the user's categories.json file. This file is .gitignored so
     | each user maintains their own personal taxonomy without touching
     | source-controlled files. Copy categories.json.example to get started.
     |
     */
    'categories_file' => __DIR__ . '/../categories.json',

    /*
     |--------------------------------------------------------------------------
     | Output Configuration
     |--------------------------------------------------------------------------
     */
    'output' => [
        'dir' => env('CTX_OUTPUT_DIR', './output'),
        'json_indent' => true,
    ],

    /*
     |--------------------------------------------------------------------------
     | Processing
     |--------------------------------------------------------------------------
     |
     | batch_size: How many conversations to send to Ollama per batch.
     | min_messages: Minimum message count to bother categorising (skip tiny chats).
     | max_context_chars: Maximum characters to send to Ollama per conversation
     |   (we truncate to stay within context window).
     |
     */
    'processing' => [
        'batch_size' => 5,
        'min_messages' => 1,
        'max_context_chars' => 8000,
    ],
];

/**
 * Simple env helper — reads from $_ENV, getenv(), or returns default.
 */
function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}