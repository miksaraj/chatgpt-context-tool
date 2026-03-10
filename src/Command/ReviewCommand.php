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
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only review specific conversation IDs (repeatable, or comma-separated)')
            ->addOption('min-relevance', null, InputOption::VALUE_REQUIRED, 'Minimum relevance score', '0.0')
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Minimum messages to include', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath       = $input->getArgument('input');
        $outputDir      = $input->getOption('output');
        $filterCategory = $input->getOption('category');
        $minRelevance   = (float) $input->getOption('min-relevance');
        $minMessages    = (int) $input->getOption('min-messages');

        // --id can be repeated (--id abc --id def) or comma-separated (--id abc,def)
        $filterIds = [];
        foreach ((array) $input->getOption('id') as $raw) {
            foreach (array_map('trim', explode(',', $raw)) as $id) {
                if ($id !== '') {
                    $filterIds[] = $id;
                }
            }
        }

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
        // 'other' is always a valid category even if absent from categories.json
        if (!in_array('other', $categorySlugs, true)) {
            $categorySlugs[] = 'other';
        }

        // Parse conversations and restore state
        $parser        = new ChatGPTExportParser();
        $conversations = $parser->parseFromPath($filePath);
        $conversations = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
        $conversations = array_values($conversations);

        $state = new StateStore($outputDir);

        $categorised = [];
        foreach ($conversations as $conv) {
            if ($state->isCategorised($conv->id)) {
                $cached               = $state->getCategorisation($conv->id);
                $conv->categories     = $cached['categories'] ?? ['other'];
                $conv->tags           = $cached['tags'] ?? [];
                $conv->summary        = $cached['summary'] ?? '';
                $conv->keyFacts       = $cached['key_facts'] ?? [];
                $conv->relevanceScore = (float) ($cached['relevance_score'] ?? 0.5);
                $categorised[]        = $conv;
            }
        }

        if (empty($categorised)) {
            $io->warning('No categorised conversations found. Run `categorise` first.');
            return Command::FAILURE;
        }

        if ($filterCategory !== null) {
            $categorised = array_filter($categorised, fn($c) =>
                in_array($filterCategory, $c->categories, true)
            );
        }

        if (!empty($filterIds)) {
            $categorised = array_filter($categorised, fn($c) =>
                in_array($c->id, $filterIds, true)
            );
        }

        $categorised = array_filter($categorised, fn($c) => $c->relevanceScore >= $minRelevance);
        $categorised = array_values($categorised);

        usort($categorised, fn($a, $b) => $b->relevanceScore <=> $a->relevanceScore);

        $io->text(sprintf('Reviewing %d conversations', count($categorised)));
        $io->newLine();

        // Build numbered index (1-based) once — shown during recategorise
        $categoryIndex = [];
        foreach ($categorySlugs as $idx => $slug) {
            $categoryIndex[$idx + 1] = $slug;
        }

        $modified = 0;

        foreach ($categorised as $i => $conv) {
            $num   = $i + 1;
            $total = count($categorised);

            $this->printConversationHeader($io, $conv, $num, $total);

            $convDirty = false;

            // Inner action loop — keep asking until the user advances or quits
            while (true) {
                $action = $io->choice('Action', [
                    'next'         => 'Next conversation (save any changes)',
                    'recategorise' => 'Change categories',
                    'relevance'    => 'Adjust relevance score',
                    'delete'       => 'Mark as irrelevant (set relevance to 0)',
                    'quit'         => 'Save changes and quit review',
                ], 'next');

                switch ($action) {
                    case 'recategorise':
                        $io->text('<info>Available categories:</info>');
                        foreach ($categoryIndex as $n => $slug) {
                            $active = in_array($slug, $conv->categories, true) ? '<comment>*</comment>' : ' ';
                            $io->text(sprintf('  %s%2d. %s', $active, $n, $slug));
                        }
                        $io->newLine();

                        // Pre-fill with current category numbers
                        $currentNums = implode(', ', array_values(array_filter(
                            array_map(
                                fn($s) => array_search($s, $categoryIndex),
                                $conv->categories,
                            ),
                            fn($v) => $v !== false,
                        )));

                        $numsInput = $io->ask(
                            'Enter category numbers (comma-separated)',
                            $currentNums !== '' ? $currentNums : null,
                        );

                        $newCats = [];
                        foreach (array_map('trim', explode(',', (string) $numsInput)) as $n) {
                            $n = (int) $n;
                            if (isset($categoryIndex[$n])) {
                                $newCats[] = $categoryIndex[$n];
                            }
                        }
                        $newCats = array_values(array_unique($newCats));

                        if (!empty($newCats)) {
                            $conv->categories = $newCats;
                            $convDirty        = true;
                            $io->text('<info>Categories set to: ' . implode(', ', $newCats) . '</info>');
                        } else {
                            $io->warning('No valid numbers entered — categories unchanged');
                        }
                        break;

                    case 'relevance':
                        $newScore             = (float) $io->ask('New relevance score (0.0–1.0)', (string) $conv->relevanceScore);
                        $conv->relevanceScore = max(0.0, min(1.0, $newScore));
                        $convDirty            = true;
                        $io->text(sprintf('<info>Relevance set to %.2f</info>', $conv->relevanceScore));
                        break;

                    case 'delete':
                        $conv->relevanceScore = 0.0;
                        $convDirty            = true;
                        $io->text('<info>Marked as irrelevant (relevance → 0.0)</info>');
                        break;

                    case 'quit':
                        if ($convDirty) {
                            $state->saveCategorisation($conv);
                            $modified++;
                        }
                        $io->success("Review ended. Modified {$modified} conversations.");
                        return Command::SUCCESS;

                    case 'next':
                    default:
                        break 2; // exit inner while
                }
            }

            // Save once per conversation on advance
            if ($convDirty) {
                $state->saveCategorisation($conv);
                $modified++;
                $io->success(sprintf(
                    'Saved — categories: %s | relevance: %.2f',
                    implode(', ', $conv->categories),
                    $conv->relevanceScore,
                ));
            }
        }

        $io->success("Review complete. Modified {$modified} conversations.");
        return Command::SUCCESS;
    }

    private function printConversationHeader(SymfonyStyle $io, mixed $conv, int $num, int $total): void
    {
        $io->section("[{$num}/{$total}] {$conv->title}");

        $io->table(
            ['Field', 'Value'],
            [
                ['Created',    $conv->createDate()],
                ['Messages',   "{$conv->messageCount()} ({$conv->userMessageCount()} user)"],
                ['Categories', implode(', ', $conv->categories)],
                ['Tags',       implode(', ', $conv->tags)],
                ['Relevance',  sprintf('%.2f', $conv->relevanceScore)],
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
    }
}