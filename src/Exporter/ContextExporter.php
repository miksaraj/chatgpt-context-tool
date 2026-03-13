<?php

declare(strict_types=1);

namespace ChatGPTContext\Exporter;

use ChatGPTContext\Parser\Conversation;

final class ContextExporter
{
    public function __construct(
        private readonly string $outputDir,
        private readonly bool $jsonIndent = true,
    ) {
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Export all categorised conversations.
     *
     * @param array<Conversation> $conversations
     * @param array<array{slug: string, name: string}> $categories
     * @return array<string, int> Files written with conversation counts
     */
    public function exportAll(array $conversations, array $categories): array
    {
        $grouped = $this->groupByCategory($conversations);
        $filesWritten = [];

        foreach ($categories as $category) {
            $slug = $category['slug'];
            $catConversations = $grouped[$slug] ?? [];

            if (empty($catConversations)) {
                continue;
            }

            // Sort by relevance within category (highest first)
            usort($catConversations, fn(Conversation $a, Conversation $b) =>
                $b->relevanceScore <=> $a->relevanceScore
            );

            // Export JSON
            $jsonPath = "{$this->outputDir}/{$slug}.json";
            $this->exportJson($jsonPath, $category, $catConversations);
            $filesWritten[$jsonPath] = count($catConversations);

            // Export Markdown
            $mdPath = "{$this->outputDir}/{$slug}.md";
            $this->exportMarkdown($mdPath, $category, $catConversations);
            $filesWritten[$mdPath] = count($catConversations);
        }

        // Export master index
        $indexPath = "{$this->outputDir}/index.json";
        $this->exportIndex($indexPath, $conversations, $categories);
        $filesWritten[$indexPath] = count($conversations);

        return $filesWritten;
    }

    /**
     * Export a single category as a context package (optimised for LLM consumption).
     *
     * @param array<Conversation> $conversations Already filtered to this category
     */
    public function exportContextPackage(
        string $slug,
        string $categoryName,
        array $conversations,
        float $minRelevance = 0.0,
    ): string {
        $filtered = array_filter(
            $conversations,
            fn(Conversation $c) => $c->relevanceScore >= $minRelevance,
        );

        usort($filtered, fn(Conversation $a, Conversation $b) =>
            $b->relevanceScore <=> $a->relevanceScore
        );

        $path = "{$this->outputDir}/context-{$slug}.md";

        $lines = [
            "# Context Package: {$categoryName}",
            "",
            "Generated: " . date('Y-m-d H:i:s'),
            "Conversations: " . count($filtered),
            "Minimum relevance: {$minRelevance}",
            "",
            "---",
            "",
        ];

        foreach ($filtered as $conv) {
            $lines[] = "## {$conv->title}";
            $lines[] = "";
            $lines[] = "**Date**: {$conv->createDate()} | **Relevance**: {$conv->relevanceScore} | **Messages**: {$conv->messageCount()}";
            $lines[] = "";

            if ($conv->summary !== '') {
                $lines[] = "**Summary**: {$conv->summary}";
                $lines[] = "";
            }

            if (!empty($conv->keyFacts)) {
                $lines[] = "**Key facts & decisions**:";
                foreach ($conv->keyFacts as $fact) {
                    $lines[] = "- {$fact}";
                }
                $lines[] = "";
            }

            if (!empty($conv->tags)) {
                $lines[] = "**Tags**: " . implode(', ', $conv->tags);
                $lines[] = "";
            }

            $lines[] = "---";
            $lines[] = "";
        }

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    /**
     * @param array<Conversation> $conversations
     * @return array<string, array<Conversation>>
     */
    private function groupByCategory(array $conversations): array
    {
        $grouped = [];

        foreach ($conversations as $conv) {
            foreach ($conv->categories as $cat) {
                $grouped[$cat][] = $conv;
            }
        }

        return $grouped;
    }

    /**
     * @param array<Conversation> $conversations
     */
    private function exportJson(string $path, array $category, array $conversations): void
    {
        $data = [
            'category' => $category,
            'exported_at' => date('Y-m-d\TH:i:sP'),
            'conversation_count' => count($conversations),
            'conversations' => array_map(fn(Conversation $c) => $c->toArray(), $conversations),
        ];

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
        if ($this->jsonIndent) {
            $flags |= JSON_PRETTY_PRINT;
        }

        file_put_contents($path, json_encode($data, $flags));
    }

    /**
     * @param array<Conversation> $conversations
     */
    private function exportMarkdown(string $path, array $category, array $conversations): void
    {
        $lines = [
            "# {$category['name']}",
            "",
            "Exported: " . date('Y-m-d H:i:s'),
            "Conversations: " . count($conversations),
            "",
            "---",
            "",
        ];

        foreach ($conversations as $conv) {
            $stars = str_repeat('★', (int) round($conv->relevanceScore * 5));
            $lines[] = "## {$conv->title}";
            $lines[] = "";
            $lines[] = "| Field | Value |";
            $lines[] = "|-------|-------|";
            $lines[] = "| Created | {$conv->createDate()} |";
            $lines[] = "| Updated | {$conv->updateDate()} |";
            $lines[] = "| Messages | {$conv->messageCount()} ({$conv->userMessageCount()} from user) |";
            $lines[] = "| Relevance | {$conv->relevanceScore} {$stars} |";
            $lines[] = "| Categories | " . implode(', ', $conv->categories) . " |";
            if (!empty($conv->tags)) {
                $lines[] = "| Tags | " . implode(', ', $conv->tags) . " |";
            }
            $lines[] = "";

            if ($conv->summary !== '') {
                $lines[] = "**Summary**: {$conv->summary}";
                $lines[] = "";
            }

            if (!empty($conv->keyFacts)) {
                $lines[] = "**Key facts**:";
                foreach ($conv->keyFacts as $fact) {
                    $lines[] = "- {$fact}";
                }
                $lines[] = "";
            }

            $lines[] = "---";
            $lines[] = "";
        }

        file_put_contents($path, implode("\n", $lines));
    }

    /**
     * Merge the current export run's category stats into the existing index.json (if any).
     *
     * Merge strategy: current-run wins per slug (updated counts/relevance), while slugs
     * that exist in the previous index but were not part of this run are preserved as-is.
     * total_conversations tracks unique conversations and is never recomputed from category
     * sums (which would over-count multi-category conversations).
     *
     * @param array<Conversation> $conversations
     */
    private function exportIndex(string $path, array $conversations, array $categories): void
    {
        // Build stats for the categories touched in this run
        $newStats = [];
        foreach ($categories as $cat) {
            $catConvs = array_filter($conversations, fn(Conversation $c) =>
                in_array($cat['slug'], $c->categories, true)
            );
            $newStats[$cat['slug']] = [
                'name' => $cat['name'],
                'count' => count($catConvs),
                'avg_relevance' => count($catConvs) > 0
                    ? round(array_sum(array_map(fn(Conversation $c) => $c->relevanceScore, $catConvs)) / count($catConvs), 3)
                    : 0,
            ];
        }

        // Use a dedicated lock file to serialise the read→merge→write sequence.
        // Without this, two overlapping export runs can both read the same old index,
        // compute independent merges, and the last rename() wins—silently losing the other's updates.
        $lockPath = $path . '.lock';
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException(sprintf('Unable to open lock file for index: %s', $lockPath));
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            throw new \RuntimeException(sprintf('Unable to acquire exclusive lock for index: %s', $lockPath));
        }

        try {
            // Load and merge with any existing index
            $existingStats = [];
            if (is_file($path)) {
                $fh = fopen($path, 'r');
                if ($fh === false) {
                    $contents = '{}';
                } else {
                    $contents = stream_get_contents($fh);
                    fclose($fh);
                    if ($contents === false) {
                        $contents = '{}';
                    }
                }

                try {
                    $existing = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($existing)) {
                        $existingStats = is_array($existing['categories'] ?? null)
                            ? $existing['categories']
                            : [];
                    }
                } catch (\JsonException) {
                    // Treat a corrupted index as empty; this run will rebuild stats only for the exported categories
                    $existingStats = [];
                }
            }

            // Existing slugs not in this run are preserved; current run overwrites its own slugs
            $mergedStats = array_merge($existingStats, $newStats);

            // Total conversations is the number of unique conversations in this export run.
            // Never derive from array_sum of category counts — multi-category conversations would
            // be double-counted.
            $totalConversations = count($conversations);

            $data = [
                'exported_at' => date('Y-m-d\TH:i:sP'),
                'total_conversations' => $totalConversations,
                'categories' => $mergedStats,
            ];

            $flags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
            if ($this->jsonIndent) {
                $flags |= JSON_PRETTY_PRINT;
            }

            $json = json_encode($data, $flags);

            // tempnam() ensures the temp file is created on the same filesystem as $path,
            // which is required for rename() to be atomic.
            $tempPath = tempnam(dirname($path), 'ctxidx_');
            if ($tempPath === false) {
                $tempPath = $path . '.' . uniqid('tmp', true);
            }

            $bytesWritten = file_put_contents($tempPath, $json, LOCK_EX);
            if ($bytesWritten === false) {
                @unlink($tempPath);
                throw new \RuntimeException(sprintf(
                    'Failed to write index to temporary file "%s".',
                    $tempPath,
                ));
            }

            if ($bytesWritten !== strlen($json)) {
                @unlink($tempPath);
                throw new \RuntimeException(sprintf(
                    'Short write when writing index to temporary file "%s" (wrote %d of %d bytes).',
                    $tempPath,
                    $bytesWritten,
                    strlen($json),
                ));
            }

            // tempnam() creates files with restrictive permissions (typically 0600).
            // Apply the existing index's mode (or a sensible default) so that
            // index.json has consistent permissions with the other exported files.
            $mode = 0644;
            if (is_file($path)) {
                $perms = @fileperms($path);
                if ($perms !== false) {
                    $candidateMode = ($perms & 0777);
                    if ($candidateMode !== 0) {
                        $mode = $candidateMode;
                    }
                }
            }
            if (!chmod($tempPath, $mode)) {
                $error = error_get_last();
                $errorMessage = $error['message'] ?? 'unknown error';
                @unlink($tempPath);
                throw new \RuntimeException(sprintf(
                    'Failed to change permissions on temporary index file "%s" to mode %o: %s',
                    $tempPath,
                    $mode,
                    $errorMessage,
                ));
            }

            // First try a direct atomic rename. On POSIX, this atomically replaces any
            // existing destination. On Windows, this will fail if the destination exists.
            if (!rename($tempPath, $path)) {
                $initialRenameError = error_get_last();
                // Windows-specific fallback: if the destination exists, rename it to a
                // backup, then move the temp file into place, and finally clean up the
                // backup. This keeps the window where the index is missing as small as
                // possible while avoiding a pre-rename unlink.
                $isWindows = (DIRECTORY_SEPARATOR === '\\');

                if ($isWindows && is_file($path)) {
                    $backupPath = $path . '.bak.' . uniqid('', true);

                    if (!rename($path, $backupPath)) {
                        $error = error_get_last();
                        $errorMessage = $error['message'] ?? 'unknown error';
                        @unlink($tempPath);
                        throw new \RuntimeException(sprintf(
                            'Failed to move existing index file "%s" to backup "%s": %s',
                            $path,
                            $backupPath,
                            $errorMessage,
                        ));
                    }

                    if (!rename($tempPath, $path)) {
                        $error = error_get_last();
                        $errorMessage = $error['message'] ?? 'unknown error';
                        // Attempt best-effort restore of the original file.
                        @rename($backupPath, $path);
                        @unlink($tempPath);
                        throw new \RuntimeException(sprintf(
                            'Failed to replace index file "%s" with temporary file "%s" after backup: %s',
                            $path,
                            $tempPath,
                            $errorMessage,
                        ));
                    }

                    // New index in place; remove the backup.
                    @unlink($backupPath);
                } else {
                    @unlink($tempPath);
                    $errorMessage = $initialRenameError['message'] ?? 'unknown error';
                    throw new \RuntimeException(sprintf(
                        'Failed to replace index file "%s" with temporary file "%s": %s',
                        $path,
                        $tempPath,
                        $errorMessage,
                    ));
                }
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
