<?php

declare(strict_types=1);

namespace ChatGPTContext\Parser;

use RuntimeException;

final class ChatGPTExportParser
{
    /**
     * Parse conversations from a single file OR every *.json file in a directory.
     *
     * When a directory is given, results from all files are merged and
     * deduplicated by conversation ID (last-write wins within a single run).
     *
     * @return array<Conversation>
     * @throws RuntimeException if the path does not exist, or a directory contains no *.json files
     */
    public function parseFromPath(string $inputPath): array
    {
        if (is_file($inputPath)) {
            return $this->parse($inputPath);
        }

        if (is_dir($inputPath)) {
            $files = glob(rtrim($inputPath, '/') . '/*.json') ?: [];
            if (empty($files)) {
                throw new RuntimeException("No *.json files found in directory: {$inputPath}");
            }

            $byId = [];
            foreach ($files as $file) {
                foreach ($this->parse($file) as $conv) {
                    $byId[$conv->id] = $conv;
                }
            }

            // Re-sort merged list by creation time, newest first
            $merged = array_values($byId);
            usort($merged, fn(Conversation $a, Conversation $b) => $b->createTime <=> $a->createTime);

            return $merged;
        }

        throw new RuntimeException("Input path does not exist: {$inputPath}");
    }

    /**
     * Parse the conversations.json file from a ChatGPT export.
     *
     * @return array<Conversation>
     */
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new RuntimeException("Could not read file: {$filePath}");
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new RuntimeException('Expected a JSON array of conversations');
        }

        $conversations = [];

        foreach ($data as $entry) {
            $conv = $this->parseConversation($entry);
            if ($conv !== null) {
                $conversations[] = $conv;
            }
        }

        // Sort by creation time, newest first
        usort($conversations, fn(Conversation $a, Conversation $b) =>
            $b->createTime <=> $a->createTime
        );

        return $conversations;
    }

    private function parseConversation(array $entry): ?Conversation
    {
        $id = $entry['id'] ?? $entry['conversation_id'] ?? null;
        $title = $entry['title'] ?? 'Untitled';
        $createTime = (float) ($entry['create_time'] ?? 0);
        $updateTime = (float) ($entry['update_time'] ?? 0);

        if ($id === null) {
            return null;
        }

        $messages = $this->extractMessages($entry);

        // Skip empty or trivially short conversations
        if (count($messages) < 2) {
            return null;
        }

        return new Conversation(
            id: $id,
            title: $title,
            createTime: $createTime,
            updateTime: $updateTime,
            messages: $messages,
        );
    }

    /**
     * Extract messages from the ChatGPT mapping structure.
     * ChatGPT exports use a tree structure with parent/children pointers.
     *
     * @return array<Message>
     */
    private function extractMessages(array $entry): array
    {
        // ChatGPT export format uses a "mapping" object with node IDs as keys
        if (isset($entry['mapping'])) {
            return $this->extractFromMapping($entry['mapping']);
        }

        // Some exports may use a flat "messages" array
        if (isset($entry['messages']) && is_array($entry['messages'])) {
            return $this->extractFromFlatMessages($entry['messages']);
        }

        return [];
    }

    /**
     * Walk the mapping tree to extract messages in order.
     * Uses an iterative DFS with an explicit stack to avoid hitting PHP's
     * call-stack limit on long (but perfectly valid) conversations.
     *
     * @return array<Message>
     */
    private function extractFromMapping(array $mapping): array
    {
        // Find the root node (no parent)
        $rootId = null;
        foreach ($mapping as $nodeId => $node) {
            if (empty($node['parent'])) {
                $rootId = $nodeId;
                break;
            }
        }

        if ($rootId === null) {
            return [];
        }

        $messages = [];
        $visited  = [];          // cycle guard
        $stack    = [$rootId];   // explicit DFS stack – no PHP call-stack growth

        while ($stack !== []) {
            $nodeId = array_pop($stack);

            // Cycle guard
            if (isset($visited[$nodeId])) {
                continue;
            }
            $visited[$nodeId] = true;

            $node = $mapping[$nodeId] ?? null;
            if ($node === null) {
                continue;
            }

            $message = $node['message'] ?? null;
            if ($message !== null) {
                $role = $message['author']['role'] ?? 'unknown';

                if (in_array($role, ['user', 'assistant'], true)) {
                    $content = $this->extractContent($message);

                    if ($content !== '') {
                        $timestamp  = (float) ($message['create_time'] ?? 0);
                        $messages[] = new Message($role, $content, $timestamp);
                    }
                }
            }

            // Push children onto the stack (reversed so left-to-right order is preserved)
            $children = $node['children'] ?? [];
            foreach (array_reverse($children) as $childId) {
                if (!isset($visited[$childId])) {
                    $stack[] = $childId;
                }
            }
        }

        return $messages;
    }

    private function extractContent(array $message): string
    {
        $content = $message['content'] ?? [];
        $contentType = $content['content_type'] ?? 'text';

        if ($contentType === 'text') {
            $parts = $content['parts'] ?? [];
            return $parts
                |> (fn($p) => array_filter($p, 'is_string'))
                |> (fn($p) => implode("\n", $p))
                |> trim(...);
        }

        if ($contentType === 'multimodal_text') {
            $parts = $content['parts'] ?? [];
            $textParts = [];
            foreach ($parts as $part) {
                if (is_string($part)) {
                    $textParts[] = $part;
                } elseif (is_array($part) && ($part['content_type'] ?? '') === 'text') {
                    $textParts[] = $part['text'] ?? '';
                }
                // Skip images and other non-text content
            }
            return trim(implode("\n", $textParts));
        }

        return '';
    }

    /**
     * @return array<Message>
     */
    private function extractFromFlatMessages(array $messages): array
    {
        $result = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? $msg['author']['role'] ?? 'unknown';
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $content = '';
            if (is_string($msg['content'] ?? null)) {
                $content = trim($msg['content']);
            } elseif (is_array($msg['content'] ?? null)) {
                $content = $this->extractContent($msg);
            }

            if ($content !== '') {
                $timestamp = (float) ($msg['create_time'] ?? $msg['timestamp'] ?? 0);
                $result[] = new Message($role, $content, $timestamp);
            }
        }

        return $result;
    }
}