<?php

declare(strict_types=1);

namespace ChatGPTContext\Parser;

use RuntimeException;

/**
 * Persists categorisation state to a JSON file so processing can be
 * resumed if interrupted (useful when running 500+ conversations through Ollama).
 */
final class StateStore
{
    private array $state;
    private readonly string $path;

    public function __construct(string $outputDir)
    {
        $this->path = rtrim($outputDir, '/') . '/.ctx-state.json';
        $this->state = $this->load();
    }

    /**
     * Check if a conversation has already been categorised (any method).
     */
    public function isCategorised(string $conversationId): bool
    {
        return isset($this->state['categorised'][$conversationId]);
    }

    /**
     * Check if a conversation was categorised successfully by the LLM.
     *
     * A successful LLM run always produces a non-empty summary or at least one tag.
     * Entries with both fields empty are treated as failed/keyword-fallback results
     * and should be retried when the LLM is available.
     */
    public function isSuccessfullyCategorised(string $conversationId): bool
    {
        $entry = $this->state['categorised'][$conversationId] ?? null;
        if ($entry === null) {
            return false;
        }

        return ($entry['summary'] ?? '') !== '' || !empty($entry['tags']);
    }

    /**
     * Store categorisation result for a conversation.
     */
    public function saveCategorisation(Conversation $conv): void
    {
        $this->state['categorised'][$conv->id] = $conv->toArray();
        $this->state['last_updated'] = date('Y-m-d\TH:i:sP');
        $this->persist();
    }

    /**
     * Get previously stored categorisation data.
     */
    public function getCategorisation(string $conversationId): ?array
    {
        return $this->state['categorised'][$conversationId] ?? null;
    }

    /**
     * Return all stored categorisation entries, keyed by conversation ID.
     *
     * @return array<string, array>
     */
    public function getAllCategorisations(): array
    {
        return $this->state['categorised'] ?? [];
    }

    /**
     * Get count of categorised conversations.
     */
    public function categorisedCount(): int
    {
        return count($this->state['categorised'] ?? []);
    }

    /**
     * Clear all state (fresh start).
     */
    public function reset(): void
    {
        $this->state = ['categorised' => [], 'last_updated' => null];
        $this->persist();
    }

    private function load(): array
    {
        if (!file_exists($this->path)) {
            return ['categorised' => [], 'last_updated' => null];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            return ['categorised' => [], 'last_updated' => null];
        }

        return json_decode($raw, true) ?? ['categorised' => [], 'last_updated' => null];
    }

    private function persist(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->path,
            json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
