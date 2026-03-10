<?php

declare(strict_types=1);

namespace ChatGPTContext\Command;

use ChatGPTContext\Categoriser\ConversationCategoriser;
use ChatGPTContext\Config\CategoryLoader;
use ChatGPTContext\Ollama\OllamaClient;
use ChatGPTContext\Parser\ChatGPTExportParser;
use ChatGPTContext\Parser\StateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'categorise', description: 'Categorise parsed conversations using Ollama or keyword fallback')]
final class CategoriseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'Path to a conversations.json file, or a directory of *.json exports')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', './output')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Ollama model to use (overrides config)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Ollama host URL', 'http://localhost:11434')
            ->addOption('keywords-only', 'k', InputOption::VALUE_NONE, 'Skip Ollama, use keyword matching only')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Reset state and re-categorise everything')
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Minimum messages to include', '4')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max conversations to process (for testing)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('input');
        $outputDir = $input->getOption('output');
        $minMessages = (int) $input->getOption('min-messages');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;

        $io->title('Conversation Categoriser');

        // Load config
        $config = require __DIR__ . '/../../config/config.php';

        // Load user categories
        if (!CategoryLoader::exists($config['categories_file'])) {
            $io->error([
                'No categories.json found.',
                'Run `./bin/ctx explore --free` to discover your categories, or create categories.json manually.',
                '(See categories.json.example for the expected format.)',
            ]);
            return Command::FAILURE;
        }

        $categories = CategoryLoader::load($config['categories_file']);

        // Parse conversations
        $io->text('Parsing export...');
        $parser = new ChatGPTExportParser();

        try {
            $conversations = $parser->parseFromPath($filePath);
        } catch (\Throwable $e) {
            $io->error("Parse failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $conversations = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
        $conversations = array_values($conversations);
        $io->text(sprintf('Loaded %d conversations', count($conversations)));

        if ($limit !== null) {
            $conversations = array_slice($conversations, 0, $limit);
            $io->note("Limited to {$limit} conversations (testing mode)");
        }

        // State management
        $state = new StateStore($outputDir);

        if ($input->getOption('reset')) {
            $state->reset();
            $io->warning('State reset — all conversations will be re-categorised');
        }

        $alreadyDone = $state->categorisedCount();
        if ($alreadyDone > 0) {
            $io->text("{$alreadyDone} conversations already categorised (resuming)");
        }

        // Set up Ollama or keyword-only mode
        $ollama = null;

        if (!$input->getOption('keywords-only')) {
            $ollamaConfig = $config['ollama'];

            if ($input->getOption('model')) {
                $ollamaConfig['model'] = $input->getOption('model');
            }
            if ($input->getOption('host')) {
                $ollamaConfig['host'] = $input->getOption('host');
            }

            $ollama = OllamaClient::fromConfig($ollamaConfig);

            if ($ollama->isAvailable()) {
                $io->success("Ollama connected — using model: {$ollama->getModel()}");
            } else {
                $io->warning("Ollama not available (model: {$ollama->getModel()}). Listing available models...");

                $models = $ollama->listModels();
                if (!empty($models)) {
                    $io->listing($models);
                    $io->text('Use --model=<name> to select one, or --keywords-only to skip LLM.');
                } else {
                    $io->text('No models found. Is Ollama running? Falling back to keyword matching.');
                }

                $ollama = null;
            }
        } else {
            $io->note('Keyword-only mode — skipping Ollama');
        }

        $categoriser = new ConversationCategoriser(
            ollama: $ollama,
            categories: $categories,
            maxContextChars: $config['processing']['max_context_chars'],
        );

        // Process conversations
        $io->progressStart(count($conversations));
        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($conversations as $conv) {
            if ($state->isCategorised($conv->id)) {
                // Restore previous categorisation
                $cached = $state->getCategorisation($conv->id);
                $conv->categories = $cached['categories'] ?? ['other'];
                $conv->tags = $cached['tags'] ?? [];
                $conv->summary = $cached['summary'] ?? '';
                $conv->keyFacts = $cached['key_facts'] ?? [];
                $conv->relevanceScore = (float) ($cached['relevance_score'] ?? 0.5);
                $skipped++;
                $io->progressAdvance();
                continue;
            }

            try {
                $categoriser->categorise($conv);
                $state->saveCategorisation($conv);
                $processed++;
            } catch (\Throwable $e) {
                $conv->categories = ['other'];
                $conv->relevanceScore = 0.0;
                $errors++;
                $io->warning("Error on '{$conv->title}': {$e->getMessage()}");
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success(sprintf(
            'Done! Processed: %d | Skipped (cached): %d | Errors: %d',
            $processed,
            $skipped,
            $errors,
        ));

        // Summary table
        $catCounts = [];
        foreach ($conversations as $conv) {
            foreach ($conv->categories as $cat) {
                $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
            }
        }

        arsort($catCounts);
        $rows = [];
        foreach ($catCounts as $slug => $count) {
            $rows[] = [$slug, (string) $count];
        }

        $io->table(['Category', 'Conversations'], $rows);

        return Command::SUCCESS;
    }
}