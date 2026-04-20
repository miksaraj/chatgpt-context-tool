<?php

declare(strict_types=1);

namespace ChatGPTContext\Enhancer;

use ChatGPTContext\Ollama\LlmResponse;
use ChatGPTContext\Ollama\OllamaClient;
use ChatGPTContext\Parser\Conversation;
use ChatGPTContext\Parser\Message;

/**
 * Runs the three-pass LLM enhancement pipeline over a single conversation:
 *
 *   Pass 1 – Per-message-pair one-liner summarisation (batched, no truncation)
 *   Pass 2 – Flag message-pair ranges that deserve a deeper look
 *   Pass 3 – Expand flagged ranges into detailed paragraph summaries
 *
 * A final `propose()` method derives suggested updates to the `summary`,
 * `key_facts`, and `tags` fields of the conversation's state entry.
 */
final class ConversationEnhancer
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly int $batchSize = 3,
    ) {}

    // -----------------------------------------------------------------------
    // Pass 1 – One-liner per message pair
    // -----------------------------------------------------------------------

    /**
     * Returns an ordered array of pair summaries:
     *
     * [
     *   ['pair_index' => 0, 'user_snippet' => '...', 'assistant_snippet' => '...', 'one_liner' => '...'],
     *   ...
     * ]
     *
     * @return array<array{pair_index: int, user_snippet: string, assistant_snippet: string, one_liner: string}>
     */
    public function summariseMessagePairs(Conversation $conv): array
    {
        $pairs  = $this->buildPairs($conv->messages);
        $result = [];

        $chunks = array_chunk($pairs, $this->batchSize, true);

        foreach ($chunks as $chunk) {
            $batchResult = $this->summarisePairBatch($chunk);
            foreach ($batchResult as $entry) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Pass 2 – Flag interesting ranges
    // -----------------------------------------------------------------------

    /**
     * Returns an array of [startPairIndex, endPairIndex] ranges that the LLM
     * considers worth a deeper look. Flagging criteria are entirely LLM-driven.
     *
     * @param  array<array{pair_index: int, one_liner: string}> $summaries
     * @return array<array{int, int}>
     */
    public function flagInterestingRanges(Conversation $conv, array $summaries): array
    {
        if (empty($summaries)) {
            return [];
        }

        $digest = '';
        foreach ($summaries as $s) {
            $digest .= "[{$s['pair_index']}] {$s['one_liner']}\n";
        }

        $system = <<<SYSTEM
You are an expert at reading conversation digests and identifying sections that deserve deeper analysis.
You will receive a numbered list of one-liner summaries (one per User/Assistant exchange).
IMPORTANT: Always respond in English.

Identify ranges of consecutive exchange indices that contain:
- A significant decision or design choice
- A back-and-forth clarification on an important topic
- A pivotal moment where the user's direction changed
- A technically or practically dense segment worth preserving verbatim

Respond ONLY with valid JSON (no markdown fences, no preamble):
{
  "flagged": [
    {"start": 0, "end": 2},
    {"start": 7, "end": 9}
  ],
  "reasoning": "Brief explanation of why these ranges were selected"
}

If no ranges deserve deeper analysis, return: {"flagged": [], "reasoning": "..."}
SYSTEM;

        $prompt = <<<PROMPT
Conversation title: "{$conv->title}"

Exchange digest (index: one-liner):
{$digest}

Identify the ranges that deserve a detailed, verbatim summary. Reply with JSON only.
PROMPT;

        try {
            $raw    = $this->ollama->generate($prompt, $system);
            $raw    = $this->stripBoilerplate($raw);
            $parsed = json_decode(LlmResponse::extractJson($raw), true, 512, JSON_THROW_ON_ERROR);

            $ranges = [];
            foreach ($parsed['flagged'] ?? [] as $r) {
                if (isset($r['start'], $r['end']) && is_int($r['start']) && is_int($r['end'])) {
                    $ranges[] = [(int) $r['start'], (int) $r['end']];
                }
            }

            return $ranges;
        } catch (\Throwable) {
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Pass 3 – Expand flagged ranges
    // -----------------------------------------------------------------------

    /**
     * Returns a map of "start-end" => "detailed paragraph summary".
     *
     * @param  array<array{int, int}>  $ranges
     * @param  array<array{pair_index: int, user_snippet: string, assistant_snippet: string, one_liner: string}> $summaries
     * @return array<string, string>   Keys are "start-end", values are paragraph summaries
     */
    public function expandFlaggedRanges(Conversation $conv, array $ranges, array $summaries): array
    {
        if (empty($ranges)) {
            return [];
        }

        $pairs    = $this->buildPairs($conv->messages);
        $detailed = [];

        foreach ($ranges as [$start, $end]) {
            $rangeKey = "{$start}-{$end}";

            // Collect the actual messages for this range
            $slice = [];
            foreach ($pairs as $pairIndex => $pair) {
                if ($pairIndex >= $start && $pairIndex <= $end) {
                    $slice[] = $pair;
                }
            }

            if (empty($slice)) {
                continue;
            }

            $transcript = '';
            foreach ($slice as $pair) {
                $transcript .= "USER:\n{$pair['user']}\n\nASSISTANT:\n{$pair['assistant']}\n\n---\n\n";
            }

            $system = <<<SYSTEM
You are a technical analyst. Your task is to write a dense, detailed summary of the provided conversation excerpt.
IMPORTANT: Always respond in English.
Capture:
- All decisions made
- The reasoning given
- Any specific values, configurations, filenames, or approaches mentioned
- How the discussion evolved across exchanges

Write between 3–6 sentences. Be precise and concrete. Do NOT add preamble like "This section discusses...".
Respond with plain text only — no JSON, no markdown headers.
SYSTEM;

            $prompt = <<<PROMPT
Conversation: "{$conv->title}"
Exchanges {$start}–{$end}:

{$transcript}

Write a detailed, dense summary of this excerpt.
PROMPT;

            try {
                $raw              = $this->ollama->generate($prompt, $system);
                $detailed[$rangeKey] = trim($this->stripBoilerplate($raw));
            } catch (\Throwable) {
                $detailed[$rangeKey] = '(Expansion failed for this range.)';
            }
        }

        return $detailed;
    }

    // -----------------------------------------------------------------------
    // State proposal
    // -----------------------------------------------------------------------

    /**
     * Given all three passes' output, ask the LLM to derive proposed updates
     * for `summary`, `key_facts`, and `tags`.
     *
     * Returns a diff struct:
     * [
     *   'summary'   => ['old' => '...', 'new' => '...'],
     *   'key_facts' => ['old' => [...], 'new' => [...]],
     *   'tags'      => ['old' => [...], 'new' => [...]],
     * ]
     *
     * Returns an empty array if nothing meaningful can be suggested.
     *
     * @param  array<string, string> $detailedSummaries  From expandFlaggedRanges()
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function proposeStateUpdates(Conversation $conv, array $detailedSummaries): array
    {
        $detailedBlock = '';
        foreach ($detailedSummaries as $range => $text) {
            $detailedBlock .= "### Exchanges {$range}\n{$text}\n\n";
        }

        if ($detailedBlock === '') {
            return [];
        }

        $currentSummary   = $conv->summary;
        $currentKeyFacts  = json_encode($conv->keyFacts,   JSON_UNESCAPED_UNICODE);
        $currentTags      = json_encode($conv->tags,        JSON_UNESCAPED_UNICODE);

        $system = <<<SYSTEM
You are a conversation archivist. You will receive the current summary, key_facts, and tags for a conversation,
along with detailed analysis of the most important sections.

Based on the detailed analysis, propose improved versions of summary, key_facts, and tags if they add
meaningful value over the current versions. Only propose a change if it is genuinely better / richer.
IMPORTANT: Always respond in English.

Respond ONLY with valid JSON (no markdown fences, no preamble):
{
  "summary": "improved summary or identical to current if no improvement",
  "key_facts": ["fact1", "fact2"],
  "tags": ["tag1", "tag2"]
}
SYSTEM;

        $prompt = <<<PROMPT
Conversation: "{$conv->title}"

CURRENT SUMMARY: {$currentSummary}
CURRENT KEY_FACTS: {$currentKeyFacts}
CURRENT TAGS: {$currentTags}

DETAILED ANALYSIS OF KEY SECTIONS:
{$detailedBlock}

Now respond with your proposed JSON updates.
PROMPT;

        try {
            $raw    = $this->ollama->generate($prompt, $system);
            $raw    = $this->stripBoilerplate($raw);
            $parsed = json_decode(LlmResponse::extractJson($raw), true, 512, JSON_THROW_ON_ERROR);

            $diff = [];

            $newSummary = trim($parsed['summary'] ?? '');
            if ($newSummary !== '' && $newSummary !== $currentSummary) {
                $diff['summary'] = ['old' => $currentSummary, 'new' => $newSummary];
            }

            $newKeyFacts = $parsed['key_facts'] ?? [];
            if (!empty($newKeyFacts) && $newKeyFacts !== $conv->keyFacts) {
                $diff['key_facts'] = ['old' => $conv->keyFacts, 'new' => $newKeyFacts];
            }

            $newTags = $parsed['tags'] ?? [];
            if (!empty($newTags) && $newTags !== $conv->tags) {
                $diff['tags'] = ['old' => $conv->tags, 'new' => $newTags];
            }

            return $diff;
        } catch (\Throwable) {
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Build User/Assistant pairs from a conversation's message list.
     * Consecutive user messages are merged; stray assistant-only turns are paired
     * with an empty user message so all content is still included.
     *
     * @param  array<Message>  $messages
     * @return array<int, array{user: string, assistant: string}>  Keyed by pair index (0-based)
     */
    private function buildPairs(array $messages): array
    {
        $pairs   = [];
        $pending = null; // accumulated user content waiting for an assistant reply

        foreach ($messages as $msg) {
            if ($msg->content === '') {
                continue;
            }

            if ($msg->role === 'user') {
                // If there's already a waiting user message (no assistant reply yet),
                // merge them so we don't lose the content.
                $pending = ($pending !== null)
                    ? $pending . "\n\n" . $msg->content
                    : $msg->content;
            } elseif ($msg->role === 'assistant') {
                $pairs[] = [
                    'user'      => $pending ?? '',
                    'assistant' => $msg->content,
                ];
                $pending = null;
            }
        }

        // Trailing user message with no assistant reply
        if ($pending !== null) {
            $pairs[] = ['user' => $pending, 'assistant' => '(no reply)'];
        }

        return $pairs;
    }

    /**
     * Send a batch of pairs to the LLM for one-liner summarisation.
     *
     * @param  array<int, array{user: string, assistant: string}>  $pairs  Keyed by original pair index
     * @return array<array{pair_index: int, user_snippet: string, assistant_snippet: string, one_liner: string}>
     */
    private function summarisePairBatch(array $pairs): array
    {
        // Build the batch payload (full content, no truncation)
        $pairList = '';
        foreach ($pairs as $idx => $pair) {
            $pairList .= "EXCHANGE {$idx}:\nUSER: {$pair['user']}\nASSISTANT: {$pair['assistant']}\n\n";
        }

        $indexList = implode(', ', array_keys($pairs));

        $system = <<<SYSTEM
You are summarising individual User/Assistant exchanges from a conversation.
For each exchange produce a single, dense one-liner (max 20 words) that describes what the user asked or stated and what the assistant concluded or provided.
Do NOT start with "The user..." — write in active topic form, e.g. "Confirmed use of PostgreSQL as primary datastore."
IMPORTANT: Always respond in English.

Respond ONLY with valid JSON (no markdown fences, no preamble):
{
  "summaries": [
    {"pair_index": 0, "one_liner": "..."},
    {"pair_index": 1, "one_liner": "..."}
  ]
}
SYSTEM;

        $prompt = <<<PROMPT
Summarise each of the following exchanges (indices: {$indexList}).

{$pairList}
Respond with JSON only — one entry per exchange.
PROMPT;

        $raw     = $this->ollama->generate($prompt, $system);
        $raw     = $this->stripBoilerplate($raw);
        $parsed  = json_decode(LlmResponse::extractJson($raw), true, 512, JSON_THROW_ON_ERROR);

        $result = [];
        $summaryMap = [];
        foreach ($parsed['summaries'] ?? [] as $item) {
            $pi = (int) ($item['pair_index'] ?? -1);
            if ($pi >= 0) {
                $summaryMap[$pi] = (string) ($item['one_liner'] ?? '');
            }
        }

        foreach ($pairs as $idx => $pair) {
            // Snippet: first 80 chars of each side for display purposes
            $userSnippet      = mb_strimwidth($pair['user'],      0, 80, '…');
            $assistantSnippet = mb_strimwidth($pair['assistant'], 0, 80, '…');

            $result[] = [
                'pair_index'        => $idx,
                'user_snippet'      => $userSnippet,
                'assistant_snippet' => $assistantSnippet,
                'one_liner'         => $summaryMap[$idx] ?? '(no summary)',
            ];
        }

        return $result;
    }

    /**
     * Strip markdown fences and <think> blocks from raw model output.
     */
    private function stripBoilerplate(string $raw): string
    {
        return $raw
            |> (fn($r) => preg_replace('/^```(?:json)?\s*/m', '', $r) ?? $r)
            |> (fn($r) => preg_replace('/```\s*$/m', '',            $r) ?? $r)
            |> (fn($r) => preg_replace('/<think>.*?<\/think>/s', '', $r) ?? $r)
            |> trim(...);
    }
}
