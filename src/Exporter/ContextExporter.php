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
     * total_conversations is recomputed as the sum of counts across the merged category map.
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

        // Load and merge with any existing index
        $existingStats = [];
        if (is_file($path)) {
            $existing = json_decode(file_get_contents($path), true);
            $existingStats = $existing['categories'] ?? [];
        }

        // Existing slugs not in this run are preserved; current run overwrites its own slugs
        $mergedStats = array_merge($existingStats, $newStats);

        // Recompute total from the merged set
        $totalConversations = array_sum(array_column($mergedStats, 'count'));

        $data = [
            'exported_at' => date('Y-m-d\\TH:i:sP'),
            'total_conversations' => $totalConversations,
            'categories' => $mergedStats,
        ];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
