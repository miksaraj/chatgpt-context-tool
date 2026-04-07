<?php

declare(strict_types=1);

namespace ChatGPTContext\Enhancer;

/**
 * Persists enhanced conversation data (message-pair summaries, flagged ranges,
 * detailed expansions, proposed state diffs) in the `enhanced` section of
 * .ctx-state.json.
 *
 * This deliberately never reads or writes the `categorised` section so that
 * the base categorisation state is always mutated only through StateStore.
 */
final class EnhancedStateStore
{
    private array $state;
    private readonly string $path;

    public function __construct(string $outputDir)
    {
        $this->path  = rtrim($outputDir, '/') . '/.ctx-state.json';
        $this->state = $this->load();
    }

    // -----------------------------------------------------------------------
    // Enhanced data (passes 1-3 output)
    // -----------------------------------------------------------------------

    public function isEnhanced(string $convId): bool
    {
        return isset($this->state['enhanced'][$convId]);
    }

    public function getEnhanced(string $convId): ?array
    {
        return $this->state['enhanced'][$convId] ?? null;
    }

    /**
     * @param array{
     *   message_summaries: array<array{pair_index: int, user_snippet: string, assistant_snippet: string, one_liner: string}>,
     *   flagged_ranges: array<array{int, int}>,
     *   detailed_summaries: array<string, string>,
     *   enhanced_summary: string,
     *   enhanced_key_facts: array<string>,
     *   proposed_tags: array<string>,
     *   enhanced_at: string,
     * } $data
     */
    public function saveEnhanced(string $convId, array $data): void
    {
        $this->state['enhanced'][$convId] = $data;
        $this->persist();
    }

    /**
     * Clear enhanced data for one conversation (e.g. on --reset).
     */
    public function clearEnhanced(string $convId): void
    {
        unset($this->state['enhanced'][$convId]);
        $this->persist();
    }

    /**
     * Clear enhanced data for all conversations in a list (e.g. scoped --reset).
     *
     * @param array<string> $convIds
     */
    public function clearEnhancedBatch(array $convIds): void
    {
        foreach ($convIds as $id) {
            unset($this->state['enhanced'][$id]);
        }
        $this->persist();
    }

    // -----------------------------------------------------------------------
    // Proposed updates to the 'categorised' section (step 5 diffs)
    // -----------------------------------------------------------------------

    /**
     * Returns the proposed state diff for a conversation, or null if no update
     * has been proposed yet.
     *
     * @return array{
     *   summary?: array{old: string, new: string},
     *   key_facts?: array{old: array<string>, new: array<string>},
     *   tags?: array{old: array<string>, new: array<string>},
     * }|null
     */
    public function getProposedUpdate(string $convId): ?array
    {
        return $this->state['enhanced'][$convId]['proposed_update'] ?? null;
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    private function load(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return [];
        }

        return json_decode($raw, true) ?? [];
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
