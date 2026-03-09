<?php

declare(strict_types=1);

namespace ChatGPTContext\Command;

use ChatGPTContext\Config\CategoryLoader;
use ChatGPTContext\Exporter\ContextExporter;
use ChatGPTContext\Parser\ChatGPTExportParser;
use ChatGPTContext\Parser\StateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'export', description: 'Export categorised conversations as JSON and Markdown')]
final class ExportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'Path to conversations.json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', './output')
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Export only this category')
            ->addOption('context-package', null, InputOption::VALUE_NONE, 'Generate LLM-optimised context package')
            ->addOption('min-relevance', null, InputOption::VALUE_REQUIRED, 'Minimum relevance for context packages', '0.3')
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Minimum messages to include', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('input');
        $outputDir = $input->getOption('output');
        $filterCategory = $input->getOption('category');
        $contextPackage = $input->getOption('context-package');
        $minRelevance = (float) $input->getOption('min-relevance');
        $minMessages = (int) $input->getOption('min-messages');

        $io->title('Context Exporter');

        $config = require __DIR__ . '/../../config/config.php';

        if (!CategoryLoader::exists($config['categories_file'])) {
            $io->error([
                'No categories.json found.',
                'Run `./bin/ctx explore --free` to discover your categories, or create categories.json manually.',
                '(See categories.json.example for the expected format.)',
            ]);
            return Command::FAILURE;
        }

        $configuredCategories = CategoryLoader::load($config['categories_file']);

        // Parse and restore
        $parser = new ChatGPTExportParser();
        $conversations = $parser->parse($filePath);
        $conversations = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
        $conversations = array_values($conversations);

        $state = new StateStore($outputDir);

        foreach ($conversations as $conv) {
            if ($state->isCategorised($conv->id)) {
                $cached = $state->getCategorisation($conv->id);
                $conv->categories = $cached['categories'] ?? ['other'];
                $conv->tags = $cached['tags'] ?? [];
                $conv->summary = $cached['summary'] ?? '';
                $conv->keyFacts = $cached['key_facts'] ?? [];
                $conv->relevanceScore = (float) ($cached['relevance_score'] ?? 0.5);
            }
        }

        $categorised = array_filter($conversations, fn($c) => !empty($c->categories));
        $categorised = array_values($categorised);

        if (empty($categorised)) {
            $io->warning('No categorised conversations. Run `categorise` first.');
            return Command::FAILURE;
        }

        $io->text(sprintf('Exporting %d categorised conversations', count($categorised)));

        $exporter = new ContextExporter($outputDir);

        if ($contextPackage) {
            // Generate context package for a specific category (or all)
            $categories = $configuredCategories;

            if ($filterCategory !== null) {
                $categories = array_filter($categories, fn($c) => $c['slug'] === $filterCategory);
            }

            foreach ($categories as $cat) {
                $catConvs = array_filter($categorised, fn($c) =>
                    in_array($cat['slug'], $c->categories, true)
                );

                if (empty($catConvs)) {
                    continue;
                }

                $path = $exporter->exportContextPackage(
                    $cat['slug'],
                    $cat['name'],
                    array_values($catConvs),
                    $minRelevance,
                );

                $io->text("  Context package: {$path} (" . count($catConvs) . " conversations)");
            }

            $io->success('Context packages generated');
        } else {
            // Standard export
            $categories = $configuredCategories;

            if ($filterCategory !== null) {
                $categories = array_filter($categories, fn($c) => $c['slug'] === $filterCategory);
            }

            $files = $exporter->exportAll($categorised, array_values($categories));

            foreach ($files as $path => $count) {
                $io->text("  {$path} ({$count} conversations)");
            }

            $io->success(sprintf('Exported %d files', count($files)));
        }

        return Command::SUCCESS;
    }
}