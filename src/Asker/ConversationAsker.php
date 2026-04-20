<?php

declare(strict_types=1);

namespace ChatGPTContext\Asker;

use ChatGPTContext\Ollama\OllamaClient;
use ChatGPTContext\Parser\Conversation;

/**
 * Answers a free-form question using a pool of conversations as context.
 *
 * Strategy (mirrors ConversationEnhancer):
 *   1. Split conversations into batches of $batchSize.
 *   2. For each batch, send full Markdown conversation text + question → partial answer.
 *   3. If more than one batch, synthesise partial answers into one final answer.
 *   4. If only one batch, the single partial answer is returned as-is.
 */
final class ConversationAsker
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly int $batchSize = 3,
        private readonly bool $debug = false,
    ) {}

    /**
     * Ask a question using the provided conversations as context.
     *
     * @param  Conversation[] $conversations  Pool of conversations to draw from.
     * @param  callable|null  $onBatchStart   Optional progress callback — receives (batchIndex, totalBatches).
     * @return AskResult
     */
    public function ask(
        string $question,
        array $conversations,
        ?callable $onBatchStart = null,
    ): AskResult {
        $batches = array_chunk($conversations, $this->batchSize);
        $totalBatches = count($batches);
        $partialAnswers = [];

        foreach ($batches as $i => $batch) {
            if ($onBatchStart !== null) {
                ($onBatchStart)($i + 1, $totalBatches);
            }

            $partialAnswers[] = $this->askBatch($question, $batch);
        }

        $answer = count($partialAnswers) === 1
            ? $partialAnswers[0]
            : $this->synthesise($question, $partialAnswers);

        return new AskResult(
            answer: $answer,
            conversations: $conversations,
            batchCount: $totalBatches,
        );
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /** @param Conversation[] $batch */
    private function askBatch(string $question, array $batch): string
    {
        $system = $this->buildBatchSystemPrompt();
        $prompt = $this->buildBatchUserPrompt($question, $batch);

        if ($this->debug) {
            echo "\n[DEBUG — batch prompt]\n{$prompt}\n[/DEBUG]\n\n";
        }

        return $this->ollama->generate($prompt, $system);
    }

    /** @param string[] $partialAnswers */
    private function synthesise(string $question, array $partialAnswers): string
    {
        $system = $this->buildSynthesisSystemPrompt();
        $prompt = $this->buildSynthesisUserPrompt($question, $partialAnswers);

        if ($this->debug) {
            echo "\n[DEBUG — synthesis prompt]\n{$prompt}\n[/DEBUG]\n\n";
        }

        return $this->ollama->generate($prompt, $system);
    }

    private function buildBatchSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are an assistant with access to a set of full conversations that a user has had
with an AI assistant. Answer the user's question as accurately as possible, drawing
only on information present in the provided conversations. If the answer is not
covered by the conversations, say so explicitly — do not hallucinate or invent
information. Cite conversation titles where helpful to support your answer.
SYSTEM;
    }

    /** @param Conversation[] $batch */
    private function buildBatchUserPrompt(string $question, array $batch): string
    {
        $parts = ['### Context Conversations', ''];

        foreach ($batch as $conv) {
            $parts[] = $conv->toMarkdown();
            $parts[] = '';
            $parts[] = '---';
            $parts[] = '';
        }

        $parts[] = '### Question';
        $parts[] = '';
        $parts[] = $question;

        return implode("\n", $parts);
    }

    private function buildSynthesisSystemPrompt(): string
    {
        return <<<'SYSTEM'
You have received partial answers to the same question, each derived from a different
batch of conversations. Synthesise them into a single, coherent, non-repetitive
answer. Do not add any information that is not present in the partial answers.
Remove repetition and contradictions; keep the most specific and detailed version
of any point. Preserve conversation title citations where present.
SYSTEM;
    }

    /** @param string[] $partialAnswers */
    private function buildSynthesisUserPrompt(string $question, array $partialAnswers): string
    {
        $parts = ['### Partial Answers', ''];

        foreach ($partialAnswers as $i => $answer) {
            $num = $i + 1;
            $parts[] = "**Answer {$num}:**";
            $parts[] = $answer;
            $parts[] = '';
        }

        $parts[] = '### Question';
        $parts[] = '';
        $parts[] = $question;

        return implode("\n", $parts);
    }
}
