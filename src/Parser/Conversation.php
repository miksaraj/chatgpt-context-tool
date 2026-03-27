<?php

declare(strict_types=1);

namespace ChatGPTContext\Parser;

final class Conversation
{
    /**
     * @param array<Message> $messages
     * @param array<string>  $categories
     * @param array<string>  $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly float $createTime,
        public readonly float $updateTime,
        public readonly array $messages,
        public array $categories = [],
        public array $tags = [],
        public string $summary = '',
        public array $keyFacts = [],
        public float $relevanceScore = 0.0,
    ) {}

    public function messageCount(): int
    {
        return count($this->messages);
    }

    public function userMessageCount(): int
    {
        return count(array_filter($this->messages, fn(Message $m) => $m->role === 'user'));
    }

    public function createDate(): string
    {
        return date('Y-m-d H:i', (int) $this->createTime);
    }

    public function updateDate(): string
    {
        return date('Y-m-d H:i', (int) $this->updateTime);
    }

    /**
     * Build a condensed text representation for LLM analysis.
     * Truncates to $maxChars to stay within context windows.
     */
    /**
     * Render the full conversation as a human-readable Markdown document.
     * Unlike toCondensedText() this method does NOT truncate any content.
     */
    public function toMarkdown(): string
    {
        $lines = [];

        $lines[] = "# {$this->title}";
        $lines[] = '';
        $lines[] = '_Created: ' . date('d M Y H:i', (int) $this->createTime) . '_';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        foreach ($this->messages as $msg) {
            if ($msg->content === '') {
                continue;
            }

            $roleHeading = $msg->role === 'user' ? '### 🧑 User' : '### 🤖 Assistant';
            $lines[] = $roleHeading;

            if ($msg->timestamp > 0) {
                $lines[] = '_' . date('d M Y H:i', (int) $msg->timestamp) . '_';
            }

            $lines[] = '';
            $lines[] = $msg->content;
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function toCondensedText(int $maxChars = 8000): string
    {
        $parts = ["# {$this->title}\n"];

        foreach ($this->messages as $msg) {
            if ($msg->content === '') {
                continue;
            }

            $roleLabel = strtoupper($msg->role);
            $content = $msg->content;

            // Truncate very long individual messages
            if (mb_strlen($content) > 1500) {
                $content = mb_substr($content, 0, 1500) . ' [...]';
            }

            $parts[] = "[{$roleLabel}]: {$content}\n";
        }

        $text = implode("\n", $parts);

        if (mb_strlen($text) > $maxChars) {
            // Keep beginning and end, which tend to be most informative
            $headSize = (int) ($maxChars * 0.6);
            $tailSize = (int) ($maxChars * 0.35);
            $text = mb_substr($text, 0, $headSize)
                . "\n\n[... middle truncated ...]\n\n"
                . mb_substr($text, -$tailSize);
        }

        return $text;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'created' => $this->createDate(),
            'updated' => $this->updateDate(),
            'message_count' => $this->messageCount(),
            'user_messages' => $this->userMessageCount(),
            'categories' => $this->categories,
            'tags' => $this->tags,
            'summary' => $this->summary,
            'key_facts' => $this->keyFacts,
            'relevance_score' => $this->relevanceScore,
        ];
    }
}
