<?php

declare(strict_types=1);

namespace ChatGPTContext\Command;

use ChatGPTContext\Config\CategoryLoader;
use ChatGPTContext\Parser\ChatGPTExportParser;
use ChatGPTContext\Parser\StateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'review', description: 'Interactively review and correct categorised conversations')]
final class ReviewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'Path to a conversations.json file, or a directory of *.json exports')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', './output')
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Filter by category slug')
            ->addOption('min-relevance', null, InputOption::VALUE_REQUIRED, 'Minimum relevance score', '0.0')
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Minimum messages to include', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('input');
        $outputDir = $input->getOption('output');
        $filterCategory = $input->getOption('category');
        $minRelevance = (float) $input->getOption('min-relevance');
        $minMessages = (int) $input->getOption('min-messages');

        $io->title('Conversation Review');

        $config = require __DIR__ . '/../../config/config.php';

        if (!CategoryLoader::exists($config['categories_file'])) {
            $io->error([
                'No categories.json found.',
                'Run `./bin/ctx explore --free` to discover your categories, or create categories.json manually.',
                '(See categories.json.example for the expected format.)',
            ]);
            return Command::FAILURE;
        }

        $categorySlugs = array_column(CategoryLoader::load($config['categories_file']), 'slug');

        // Parse and restore state
        $parser = new ChatGPTExportParser();
        $conversations = $parser->parseFromPath($filePath);
        $conversations = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
        $conversations = array_values($conversations);

        $state = new StateStore($outputDir);

        // Restore categorisations
        $categorised = [];
        foreach ($conversations as $conv) {
            if ($state->isCategorised($conv->id)) {
                $cached = $state->getCategorisation($conv->id);
                $conv->categories = $cached['categories'] ?? ['other'];
                $conv->tags = $cached['tags'] ?? [];
                $conv->summary = $cached['summary'] ?? '';
                $conv->keyFacts = $cached['key_facts'] ?? [];
                $conv->relevanceScore = (float) ($cached['relevance_score'] ?? 0.5);
                $categorised[] = $conv;
            }
        }

        if (empty($categorised)) {
            $io->warning('No categorised conversations found. Run `categorise` first.');
            return Command::FAILURE;
        }

        // Apply filters
        if ($filterCategory !== null) {
            $categorised = array_filter($categorised, fn($c) =>
                in_array($filterCategory, $c->categories, true)
            );
        }

        $categorised = array_filter($categorised, fn($c) =>
            $c->relevanceScore >= $minRelevance
        );

        $categorised = array_values($categorised);

        // Sort by relevance desc
        usort($categorised, fn($a, $b) => $b->relevanceScore <=> $a->relevanceScore);

        $io->text(sprintf('Reviewing %d conversations', count($categorised)));
        $io->newLine();

        $modified = 0;

        foreach ($categorised as $i => $conv) {
            $num = $i + 1;
            $total = count($categorised);

            $io->section("[{$num}/{$total}] {$conv->title}");

            $io->table(
                ['Field', 'Value'],
                [
                    ['Created', $conv->createDate()],
                    ['Messages', "{$conv->messageCount()} ({$conv->userMessageCount()} user)"],
                    ['Categories', implode(', ', $conv->categories)],
                    ['Tags', implode(', ', $conv->tags)],
                    ['Relevance', (string) $conv->relevanceScore],
                ],
            );

            if ($conv->summary !== '') {
                $io->text("<info>Summary:</info> {$conv->summary}");
            }

            if (!empty($conv->keyFacts)) {
                $io->text('<info>Key facts:</info>');
                foreach ($conv->keyFacts as $fact) {
                    $io->text("  • {$fact}");
                }
            }

            $io->newLine();

            $action = $io->choice('Action', [
                'skip' => 'Skip (keep as-is)',
                'recategorise' => 'Change categories',
                'relevance' => 'Adjust relevance score',
                'delete' => 'Mark as irrelevant (set relevance to 0)',
                'quit' => 'Save and quit review',
            ], 'skip');

            switch ($action) {
                case 'recategorise':
                    $newCats = $io->choice(
                        'Select categories (comma-separated indices)',
                        $categorySlugs,
                        implode(',', array_map(fn($c) => (string) array_search($c, $categorySlugs), $conv->categories)),
                    );
                    // Symfony choice returns a single value; for multi, we'll handle it manually
                    $newCatsInput = $io->ask('Enter category slugs (comma-separated)', implode(', ', $conv->categories));
                    $newCats = array_map('trim', explode(',', $newCatsInput));
                    $newCats = array_filter($newCats, fn($c) => in_array($c, $categorySlugs, true));

                    if (!empty($newCats)) {
                        $conv->categories = $newCats;
                        $state->saveCategorisation($conv);
                        $modified++;
                        $io->success('Categories updated');
                    } else {
                        $io->warning('No valid categories — keeping original');
                    }
                    break;

                case 'relevance':
                    $newScore = (float) $io->ask('New relevance score (0.0-1.0)', (string) $conv->relevanceScore);
                    $conv->relevanceScore = max(0.0, min(1.0, $newScore));
                    $state->saveCategorisation($conv);
                    $modified++;
                    $io->success("Relevance set to {$conv->relevanceScore}");
                    break;

                case 'delete':
                    $conv->relevanceScore = 0.0;
                    $state->saveCategorisation($conv);
                    $modified++;
                    $io->success('Marked as irrelevant');
                    break;

                case 'quit':
                    $io->success("Review ended. Modified {$modified} conversations.");
                    return Command::SUCCESS;

                default:
                    break;
            }
        }

        $io->success("Review complete. Modified {$modified} conversations.");

        return Command::SUCCESS;
    }
}