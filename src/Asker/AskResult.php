<?php

declare(strict_types=1);

namespace ChatGPTContext\Asker;

use ChatGPTContext\Parser\Conversation;

/**
 * Value object returned by ConversationAsker::ask().
 */
final class AskResult
{
    /**
     * @param Conversation[] $conversations  Conversations used as context.
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $conversations,
        public readonly int $batchCount,
    ) {}

    /**
     * Return a formatted "Sources" block listing conversation titles.
     */
    public function sourcesText(): string
    {
        if (empty($this->conversations)) {
            return '';
        }

        $lines = ['Sources:'];
        foreach ($this->conversations as $conv) {
            $lines[] = "  • {$conv->title} ({$conv->createDate()})";
        }

        return implode("\n", $lines);
    }

    /**
     * Render the full answer as Markdown, ready to save to a file.
     */
    public function toMarkdown(string $question): string
    {
        $lines = [
            '# Ask: ' . $question,
            '',
            '_Generated: ' . date('d M Y H:i') . '_',
            '',
            '---',
            '',
            $this->answer,
            '',
            '---',
            '',
            '## Sources',
            '',
        ];

        foreach ($this->conversations as $conv) {
            $lines[] = "- **{$conv->title}** ({$conv->createDate()})";
        }

        return implode("\n", $lines) . "\n";
    }
}
