<?php

declare(strict_types=1);

namespace ChatGPTContext\Exporter;

use ChatGPTContext\Parser\Conversation;

/**
 * Writes enhanced-context-{slug}.md files.
 *
 * The enhanced format extends the standard context package with:
 *   - A full message-pair digest (one-liner per exchange)
 *   - Deep-dive sections for ranges flagged by the LLM
 */
final class EnhancedContextExporter
{
    public function __construct(
        private readonly string $outputDir,
    ) {
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Export the enhanced context package for one category.
     *
     * @param string              $slug
     * @param string              $categoryName
     * @param array<Conversation> $conversations  Already filtered and sorted by relevance
     * @param array<string, array{
     *   message_summaries: array<array{pair_index: int, user_snippet: string, assistant_snippet: string, one_liner: string}>,
     *   flagged_ranges: array<array{int, int}>,
     *   detailed_summaries: array<string, string>,
     *   enhanced_summary: string,
     *   enhanced_key_facts: array<string>,
     * }> $enhancedData  Keyed by conversation ID
     */
    public function export(
        string $slug,
        string $categoryName,
        array $conversations,
        array $enhancedData,
        float $minRelevance = 0.0,
    ): string {
        $filtered = array_filter(
            $conversations,
            fn(Conversation $c) => $c->relevanceScore >= $minRelevance,
        );
        usort($filtered, fn(Conversation $a, Conversation $b) =>
            $b->relevanceScore <=> $a->relevanceScore
        );

        $path  = "{$this->outputDir}/enhanced-context-{$slug}.md";
        $lines = [
            "# Enhanced Context Package: {$categoryName}",
            '',
            'Generated: ' . date('Y-m-d H:i:s'),
            'Conversations: ' . count($filtered),
            'Minimum relevance: ' . $minRelevance,
            '',
            '---',
            '',
        ];

        foreach ($filtered as $conv) {
            $enhanced = $enhancedData[$conv->id] ?? null;

            $lines[] = "## {$conv->title}";
            $lines[] = '';
            $lines[] = "**Date**: {$conv->createDate()} | **Updated**: {$conv->updateDate()} | **Relevance**: {$conv->relevanceScore} | **Messages**: {$conv->messageCount()}";
            $lines[] = '';

            // --- Summary (prefer enhanced, fall back to categorised) ---
            $summary = ($enhanced['enhanced_summary'] ?? '') !== ''
                ? $enhanced['enhanced_summary']
                : $conv->summary;

            if ($summary !== '') {
                $lines[] = "**Summary**: {$summary}";
                $lines[] = '';
            }

            // --- Key facts (prefer enhanced) ---
            $keyFacts = !empty($enhanced['enhanced_key_facts'])
                ? $enhanced['enhanced_key_facts']
                : $conv->keyFacts;

            if (!empty($keyFacts)) {
                $lines[] = '**Key Facts & Decisions**:';
                foreach ($keyFacts as $fact) {
                    $lines[] = "- {$fact}";
                }
                $lines[] = '';
            }

            // --- Tags ---
            if (!empty($conv->tags)) {
                $lines[] = '**Tags**: ' . implode(', ', $conv->tags);
                $lines[] = '';
            }

            // --- Message-pair digest ---
            $messageSummaries = $enhanced['message_summaries'] ?? [];
            if (!empty($messageSummaries)) {
                $lines[] = '**Conversation Digest** *(message-pair one-liners)*:';
                $lines[] = '';
                foreach ($messageSummaries as $entry) {
                    $n       = (int) $entry['pair_index'] + 1;
                    $oneLiner = $entry['one_liner'];
                    $lines[] = "{$n}. {$oneLiner}";
                }
                $lines[] = '';
            }

            // --- Deep-dive sections ---
            $detailedSummaries = $enhanced['detailed_summaries'] ?? [];
            if (!empty($detailedSummaries)) {
                $lines[] = '**Deep-Dive Sections**:';
                $lines[] = '';

                foreach ($detailedSummaries as $range => $text) {
                    [$start, $end] = array_map('intval', explode('-', $range, 2));
                    $startHuman = $start + 1;
                    $endHuman   = $end   + 1;
                    $lines[] = "### Exchanges {$startHuman}–{$endHuman}";
                    $lines[] = '';
                    $lines[] = $text;
                    $lines[] = '';
                }
            }

            $lines[] = '---';
            $lines[] = '';
        }

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }
}
