<?php

declare(strict_types=1);

namespace ChatGPTContext\Ollama;

use RuntimeException;

final class OllamaClient
{
    public function __construct(
        private readonly string $host = 'http://localhost:11434',
        private readonly string $model = 'deepseek-r1',
        private readonly int $timeout = 120,
        private readonly float $temperature = 0.1,
        private readonly int $maxTokens = 1024,
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self(
            host: $config['host'] ?? 'http://localhost:11434',
            model: $config['model'] ?? 'deepseek-r1',
            timeout: $config['timeout'] ?? 120,
            temperature: $config['temperature'] ?? 0.1,
            maxTokens: $config['max_tokens'] ?? 1024,
        );
    }

    /**
     * Check if Ollama is reachable and the configured model is available.
     */
    public function isAvailable(): bool
    {
        try {
            $ch = curl_init("{$this->host}/api/tags");
            if ($ch === false) {
                return false;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 200 || $response === false) {
                return false;
            }

            $data   = json_decode($response, true);
            $models = array_column($data['models'] ?? [], 'name');

            // Accept both "model:tag" and bare "model" names
            foreach ($models as $modelName) {
                $baseName = explode(':', $modelName)[0];
                if ($baseName === $this->model || $modelName === $this->model) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get available model names from Ollama.
     *
     * @return array<string>
     */
    public function listModels(): array
    {
        $ch = curl_init("{$this->host}/api/tags");
        if ($ch === false) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);

        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        return array_column($data['models'] ?? [], 'name');
    }

    /**
     * Send a prompt to Ollama via /api/generate and return the response text.
     */
    public function generate(string $prompt, ?string $system = null): string
    {
        $payload = [
            'model'  => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $this->temperature,
                'num_predict' => $this->maxTokens,
            ],
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        $ch = curl_init("{$this->host}/api/generate");
        if ($ch === false) {
            throw new RuntimeException('Failed to initialise cURL handle');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS    => json_encode($payload),
            CURLOPT_TIMEOUT       => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        return $this->readGenerateResponse(curl_exec($ch), curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_error($ch));
    }

    public function getModel(): string
    {
        return $this->model;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private function readGenerateResponse(string|false $raw, int $httpCode, string $curlError): string
    {
        if ($raw === false) {
            throw new RuntimeException("Ollama request failed: {$curlError}");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("Ollama returned HTTP {$httpCode}: {$raw}");
        }

        $data = json_decode($raw, true);

        // Ollama sometimes returns NDJSON (one JSON object per line) even when
        // stream:false is requested. Fall back to line-by-line parsing.
        if (!is_array($data)) {
            $combined = '';
            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $lineData = json_decode($line, true);
                if (is_array($lineData) && isset($lineData['response'])) {
                    $combined .= $lineData['response'];
                }
            }
            if ($combined === '') {
                throw new RuntimeException('Ollama response could not be parsed. Raw body: ' . mb_substr($raw, 0, 300));
            }
            return trim($combined);
        }

        if (!isset($data['response'])) {
            throw new RuntimeException("Ollama response missing 'response' field. Raw body: " . mb_substr($raw, 0, 300));
        }

        return trim($data['response']);
    }
}