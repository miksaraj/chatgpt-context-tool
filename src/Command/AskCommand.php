<?php

declare(strict_types=1);

namespace ChatGPTContext\Command;

use ChatGPTContext\Asker\ConversationAsker;
use ChatGPTContext\Ollama\OllamaClient;
use ChatGPTContext\Parser\ChatGPTExportParser;
use ChatGPTContext\Parser\Conversation;
use ChatGPTContext\Parser\StateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ask', description: 'Ask a question about your conversations using Ollama')]
final class AskCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'The question to ask')
            ->addArgument('input', InputArgument::OPTIONAL, 'Path to a conversations.json file or a directory of *.json exports (not needed if state already exists)')
            ->addOption('output',        'o',  InputOption::VALUE_REQUIRED, 'Output directory',                        './output')
            ->addOption('category',      'c',  InputOption::VALUE_REQUIRED, 'Restrict context to this category slug')
            ->addOption('conv',          null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to specific conversation ID(s) (repeatable, or comma-separated)')
            ->addOption('model',         'm',  InputOption::VALUE_REQUIRED, 'Ollama model to use (overrides config)')
            ->addOption('host',          null, InputOption::VALUE_REQUIRED, 'Ollama host URL',                         'http://localhost:11434')
            ->addOption('max-tokens',    null, InputOption::VALUE_REQUIRED, 'Max tokens for LLM output (increase for reasoning models)')
            ->addOption('min-relevance', null, InputOption::VALUE_REQUIRED, 'Minimum relevance score to include',      '0.3')
            ->addOption('batch-size',    null, InputOption::VALUE_REQUIRED, 'Conversations per LLM batch',             '3')
            ->addOption('min-messages',  null, InputOption::VALUE_REQUIRED, 'Minimum messages to include',             '4')
            ->addOption('save',          null, InputOption::VALUE_NONE,     'Save the answer to a Markdown file in the output directory')
            ->addOption('debug',         null, InputOption::VALUE_NONE,     'Print each assembled batch prompt before sending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io             = new SymfonyStyle($input, $output);
        $question       = (string) $input->getArgument('question');
        $inputPath      = $input->getArgument('input');
        $outputDir      = $input->getOption('output');
        $filterCategory = $input->getOption('category');
        $minRelevance   = (float) $input->getOption('min-relevance');
        $batchSize      = max(1, (int) $input->getOption('batch-size'));
        $minMessages    = (int) $input->getOption('min-messages');
        $doSave         = (bool) $input->getOption('save');
        $debug          = (bool) $input->getOption('debug');

        // Parse --conv: repeatable flag, each value may itself be comma-separated
        $filterIds = [];
        foreach ((array) $input->getOption('conv') as $raw) {
            foreach (array_map('trim', explode(',', $raw)) as $id) {
                if ($id !== '') {
                    $filterIds[] = $id;
                }
            }
        }

        $io->title('Ask');

        // --- Config ---
        $config = require __DIR__ . '/../../config/config.php';

        // --- Ollama ---
        $ollamaConfig = $config['ollama'];
        if ($input->getOption('model')) {
            $ollamaConfig['model'] = $input->getOption('model');
        }
        if ($input->getOption('host')) {
            $ollamaConfig['host'] = $input->getOption('host');
        }
        if ($input->getOption('max-tokens') !== null) {
            $ollamaConfig['max_tokens'] = (int) $input->getOption('max-tokens');
        }

        $ollama = OllamaClient::fromConfig($ollamaConfig);

        if (!$ollama->isAvailable()) {
            $io->error([
                "Ollama is not available (model: {$ollama->getModel()}).",
                'The ask command requires an LLM — ensure Ollama is running and the model is pulled.',
            ]);
            $models = $ollama->listModels();
            if (!empty($models)) {
                $io->text('Available models:');
                $io->listing($models);
            }
            return Command::FAILURE;
        }

        $io->success("Ollama connected — using model: {$ollama->getModel()}");

        // --- Resolve conversation pool ---
        $conversations = $this->resolveConversations(
            io: $io,
            inputPath: $inputPath,
            outputDir: $outputDir,
            filterCategory: $filterCategory,
            filterIds: $filterIds,
            minRelevance: $minRelevance,
            minMessages: $minMessages,
        );

        if ($conversations === null) {
            // resolveConversations already emitted the error
            return Command::FAILURE;
        }

        if (empty($conversations)) {
            $io->warning('No conversations matched the given filters.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Using <info>%d</info> conversation(s) as context.', count($conversations)));
        $io->newLine();

        // --- Ask ---
        $asker = new ConversationAsker(
            ollama: $ollama,
            batchSize: $batchSize,
            debug: $debug,
        );

        $totalBatches = (int) ceil(count($conversations) / $batchSize);

        $progressBar = $io->createProgressBar($totalBatches);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $progressBar->setMessage('Sending batch…');
        $progressBar->start();

        $result = $asker->ask(
            question: $question,
            conversations: $conversations,
            onBatchStart: function (int $batchNum, int $total) use ($progressBar) {
                $progressBar->setMessage("Batch {$batchNum}/{$total}…");
                $progressBar->advance();
            },
        );

        // Advance any remaining steps (synthesis doesn't trigger onBatchStart)
        while ($progressBar->getProgress() < $totalBatches) {
            $progressBar->advance();
        }
        $progressBar->finish();
        $io->newLine(2);

        // --- Output: always print to terminal ---
        $io->section('Answer');
        $io->writeln($result->answer);
        $io->newLine();
        $io->text($result->sourcesText());

        // --- Output: optionally save to file ---
        if ($doSave) {
            $timestamp  = date('Y-m-d_H-i-s');
            $prefix     = $filterCategory !== null ? $filterCategory : 'ask';
            $filename   = "{$prefix}-answer-{$timestamp}.md";

            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $savePath = rtrim($outputDir, '/') . '/' . $filename;
            file_put_contents($savePath, $result->toMarkdown($question));
            $io->success("Answer saved to: {$savePath}");
        }

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Conversation resolution
    // -----------------------------------------------------------------------

    /**
     * Resolve the pool of conversations to use as context.
     *
     * Priority:
     *   1. --conv <id> – specific IDs from state.
     *   2. --category <slug> + state – conversations in that category.
     *   3. All categorised conversations from state, filtered by relevance.
     *   4. (Fallback) Parse raw export on-the-fly when state is empty and input is provided.
     *
     * Returns null on unrecoverable error (error already printed).
     *
     * @param  string[]    $filterIds
     * @return Conversation[]|null
     */
    private function resolveConversations(
        SymfonyStyle $io,
        ?string $inputPath,
        string $outputDir,
        ?string $filterCategory,
        array $filterIds,
        float $minRelevance,
        int $minMessages,
    ): ?array {
        $state = new StateStore($outputDir);
        $hasState = $state->categorisedCount() > 0;

        // ── Path A: specific conversation IDs ──────────────────────────────
        if (!empty($filterIds)) {
            if (!$hasState && $inputPath === null) {
                $io->error([
                    'No categorised state found and no input path provided.',
                    'Either run `categorise` first, or provide the path to a conversations.json export.',
                ]);
                return null;
            }

            $pool = $this->loadFromState($state, $inputPath, $minMessages, $io);
            if ($pool === null) {
                return null;
            }

            $byId = [];
            foreach ($pool as $conv) {
                $byId[$conv->id] = $conv;
            }

            $selected = [];
            $missing  = [];
            foreach ($filterIds as $id) {
                if (isset($byId[$id])) {
                    $selected[] = $byId[$id];
                } else {
                    $missing[] = $id;
                }
            }

            if (!empty($missing)) {
                $io->warning('Conversation ID(s) not found: ' . implode(', ', $missing));
            }

            return $selected;
        }

        // ── Paths B & C: state-based (category or all) ─────────────────────
        if ($hasState) {
            $pool = $this->loadFromState($state, $inputPath, $minMessages, $io);
            if ($pool === null) {
                return null;
            }

            // Restore metadata from state
            foreach ($pool as $conv) {
                if ($state->isCategorised($conv->id)) {
                    $cached                = $state->getCategorisation($conv->id);
                    $conv->categories      = $cached['categories']     ?? ['other'];
                    $conv->tags            = $cached['tags']           ?? [];
                    $conv->summary         = $cached['summary']        ?? '';
                    $conv->keyFacts        = $cached['key_facts']      ?? [];
                    $conv->relevanceScore  = (float) ($cached['relevance_score'] ?? 0.5);
                }
            }

            $pool = array_filter($pool, fn($c) => !empty($c->categories));

            if ($filterCategory !== null) {
                $pool = array_filter($pool, fn($c) => in_array($filterCategory, $c->categories, true));

                if (empty($pool)) {
                    $io->error("No conversations found for category slug '{$filterCategory}'.");
                    return null;
                }
            }

            $pool = array_filter($pool, fn($c) => $c->relevanceScore >= $minRelevance);
            $pool = array_values($pool);

            // Sort by relevance descending
            usort($pool, fn($a, $b) => $b->relevanceScore <=> $a->relevanceScore);

            return $pool;
        }

        // ── Path D: no state — parse raw export on-the-fly ─────────────────
        if ($inputPath === null) {
            $io->error([
                'No categorised state found and no input path provided.',
                'Run `categorise` first so the tool can rank conversations by relevance,',
                'or pass the path to a conversations.json export to answer from raw data.',
            ]);
            return null;
        }

        $io->note('No categorisation state found — parsing raw export (no relevance ranking available).');

        $parser = new ChatGPTExportParser();
        try {
            $conversations = $parser->parseFromPath($inputPath);
        } catch (\Throwable $e) {
            $io->error("Parse failed: {$e->getMessage()}");
            return null;
        }

        $conversations = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
        return array_values($conversations);
    }

    /**
     * Load the conversation pool.
     *
     * If an input path is provided, parse it and hydrate IDs from state.
     * Otherwise, reconstruct Conversation stubs from state data so that the
     * full message text is available via toMarkdown().
     *
     * @return Conversation[]|null
     */
    private function loadFromState(
        StateStore $state,
        ?string $inputPath,
        int $minMessages,
        SymfonyStyle $io,
    ): ?array {
        if ($inputPath !== null) {
            $parser = new ChatGPTExportParser();
            try {
                $conversations = $parser->parseFromPath($inputPath);
            } catch (\Throwable $e) {
                $io->error("Parse failed: {$e->getMessage()}");
                return null;
            }

            return array_values(
                array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages),
            );
        }

        // No input path — we can still filter/rank by state but we won't have message bodies.
        // Return empty array; the caller will use state metadata only.
        // (The LLM will receive summaries/key_facts instead of full conversation text in this case.)
        $io->note(
            'No input file provided — answering from categorisation metadata (summaries & key facts) rather than full conversation text. ' .
            'For richer answers, pass the path to the original conversations.json export.',
        );

        return $this->buildConversationsFromState($state);
    }

    /**
     * Build lightweight Conversation objects from state metadata (no full message bodies).
     * Used so the command can still function with just a state file — no export needed.
     *
     * Each conversation gets a single synthetic message containing the LLM-generated
     * summary and key facts, so the asker still has something meaningful to reason over.
     *
     * @return Conversation[]
     */
    private function buildConversationsFromState(StateStore $state): array
    {
        $conversations = [];

        foreach ($state->getAllCategorisations() as $id => $data) {
            $conversations[] = $this->buildSummaryConversation($id, $data);
        }

        return $conversations;
    }

    /**
     * Build a Conversation stub from a state entry.
     * The stub carries no raw message bodies — only the metadata the categoriser stored.
     */
    private function buildSummaryConversation(string $id, array $data): Conversation
    {
        $summaryText = '';

        if (($data['summary'] ?? '') !== '') {
            $summaryText .= "**Summary:** {$data['summary']}\n\n";
        }

        if (!empty($data['key_facts'])) {
            $summaryText .= "**Key facts:**\n";
            foreach ($data['key_facts'] as $fact) {
                $summaryText .= "- {$fact}\n";
            }
            $summaryText .= "\n";
        }

        if (!empty($data['tags'])) {
            $summaryText .= '**Tags:** ' . implode(', ', $data['tags']) . "\n";
        }

        $message = new \ChatGPTContext\Parser\Message(
            role: 'assistant',
            content: $summaryText !== '' ? $summaryText : '(no summary available)',
            timestamp: 0,
        );

        return new Conversation(
            id: $id,
            title: $data['title'] ?? $id,
            createTime: strtotime($data['created'] ?? 'now') ?: time(),
            updateTime: strtotime($data['updated'] ?? 'now') ?: time(),
            messages: [$message],
            categories: $data['categories'] ?? [],
            tags: $data['tags'] ?? [],
            summary: $data['summary'] ?? '',
            keyFacts: $data['key_facts'] ?? [],
            relevanceScore: (float) ($data['relevance_score'] ?? 0.5),
        );
    }
}
