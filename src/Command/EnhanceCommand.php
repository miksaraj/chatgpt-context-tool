<?php

declare(strict_types=1);

namespace ChatGPTContext\Command;

use ChatGPTContext\Config\CategoryLoader;
use ChatGPTContext\Enhancer\ConversationEnhancer;
use ChatGPTContext\Enhancer\EnhancedStateStore;
use ChatGPTContext\Exporter\EnhancedContextExporter;
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

#[AsCommand(name: 'enhance', description: 'Deep-summarise categorised conversations and export enhanced context packages')]
final class EnhanceCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'Path to a conversations.json file, or a directory of *.json exports')
            ->addOption('output',       'o',  InputOption::VALUE_REQUIRED, 'Output directory',                                 './output')
            ->addOption('category',     'c',  InputOption::VALUE_REQUIRED, 'Process only this category slug')
            ->addOption('model',        'm',  InputOption::VALUE_REQUIRED, 'Ollama model to use (overrides config)')
            ->addOption('host',         null, InputOption::VALUE_REQUIRED, 'Ollama host URL',                                  'http://localhost:11434')
            ->addOption('batch-size',   null, InputOption::VALUE_REQUIRED, 'Message pairs per LLM batch (Pass 1)',             '3')
            ->addOption('max-tokens',   null, InputOption::VALUE_REQUIRED, 'Max tokens for LLM output',                       '4096')
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Minimum messages to include',                     '4')
            ->addOption('min-relevance',null, InputOption::VALUE_REQUIRED, 'Minimum relevance for export',                    '0.3')
            ->addOption('reset',        null, InputOption::VALUE_NONE,     'Clear enhanced state for covered conversations and re-process')
            ->addOption('yes',          'y',  InputOption::VALUE_NONE,     'Auto-accept all proposed state updates (no prompts)')
            ->addOption('skip-state-update', null, InputOption::VALUE_NONE, 'Skip the interactive state-update step (passes 1–3 and export still run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $filePath      = $input->getArgument('input');
        $outputDir     = $input->getOption('output');
        $filterCategory = $input->getOption('category');
        $batchSize     = max(1, (int) $input->getOption('batch-size'));
        $minMessages   = (int) $input->getOption('min-messages');
        $minRelevance  = (float) $input->getOption('min-relevance');
        $autoYes       = (bool) $input->getOption('yes');
        $skipStateUpd  = (bool) $input->getOption('skip-state-update');

        $io->title('Context Enhancer');

        // --- Config & categories ---
        $config = require __DIR__ . '/../../config/config.php';

        if (!CategoryLoader::exists($config['categories_file'])) {
            $io->error([
                'No categories.json found.',
                'Run `./bin/ctx explore --free` to discover your categories first.',
            ]);
            return Command::FAILURE;
        }

        $configuredCategories = CategoryLoader::load($config['categories_file']);

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
                'Ensure Ollama is running and the model is pulled before running enhance.',
            ]);
            return Command::FAILURE;
        }

        $io->success("Ollama connected — using model: {$ollama->getModel()}");

        // --- Parse & restore categorisation ---
        $parser        = new ChatGPTExportParser();
        $conversations = $parser->parseFromPath($filePath);
        $conversations = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
        $conversations = array_values($conversations);

        $state = new StateStore($outputDir);

        foreach ($conversations as $conv) {
            if ($state->isCategorised($conv->id)) {
                $cached                = $state->getCategorisation($conv->id);
                $conv->categories      = $cached['categories']     ?? ['other'];
                $conv->tags            = $cached['tags']           ?? [];
                $conv->summary         = $cached['summary']        ?? '';
                $conv->keyFacts        = $cached['key_facts']      ?? [];
                $conv->relevanceScore  = (float) ($cached['relevance_score'] ?? 0.5);
            }
        }

        $categorised = array_filter($conversations, fn($c) => !empty($c->categories));
        $categorised = array_values($categorised);

        if (empty($categorised)) {
            $io->warning('No categorised conversations found. Run `categorise` first.');
            return Command::FAILURE;
        }

        // --- Determine categories to process ---
        $categories = $configuredCategories;
        if ($filterCategory !== null) {
            $categories = array_filter($categories, fn($c) => $c['slug'] === $filterCategory);
            if (empty($categories)) {
                $io->error("Category slug '{$filterCategory}' not found in categories.json.");
                return Command::FAILURE;
            }
        }

        // --- Enhanced state & services ---
        $enhancedStore = new EnhancedStateStore($outputDir);
        $enhancer      = new ConversationEnhancer($ollama, $batchSize);
        $exporter      = new EnhancedContextExporter($outputDir);

        // --- Process each category ---
        $totalProcessed = 0;
        $totalSkipped   = 0;
        $exportedFiles  = [];

        foreach ($categories as $cat) {
            $slug    = $cat['slug'];
            $catName = $cat['name'];

            $catConvs = array_filter(
                $categorised,
                fn(Conversation $c) => in_array($slug, $c->categories, true),
            );
            $catConvs = array_values($catConvs);

            if (empty($catConvs)) {
                continue;
            }

            $io->section("Category: {$catName} ({$slug}) — " . count($catConvs) . ' conversations');

            // Optionally reset enhanced state for this batch
            if ($input->getOption('reset')) {
                $enhancedStore->clearEnhancedBatch(array_map(fn($c) => $c->id, $catConvs));
                $io->note("Enhanced state cleared for {$slug}.");
            }

            // Collect enhanced data for the exporter
            $enhancedData = [];

            $progressBar = $io->createProgressBar(count($catConvs));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
            $progressBar->setMessage('Starting…');
            $progressBar->start();

            foreach ($catConvs as $conv) {
                $progressBar->setMessage(mb_strimwidth($conv->title, 0, 50, '…'));

                // Skip if already enhanced
                if ($enhancedStore->isEnhanced($conv->id)) {
                    $enhancedData[$conv->id] = $enhancedStore->getEnhanced($conv->id);
                    $totalSkipped++;
                    $progressBar->advance();
                    continue;
                }

                try {
                    // --- Pass 1: one-liner summarisation ---
                    $messageSummaries = $enhancer->summariseMessagePairs($conv);

                    // --- Pass 2: flag interesting ranges ---
                    $flaggedRanges = $enhancer->flagInterestingRanges($conv, $messageSummaries);

                    // --- Pass 3: expand flagged ranges ---
                    $detailedSummaries = $enhancer->expandFlaggedRanges($conv, $flaggedRanges, $messageSummaries);

                    // Build enhanced summary from the detailed summaries (concatenation for storage)
                    $enhancedSummary  = $conv->summary; // default; proposal step may improve it
                    $enhancedKeyFacts = $conv->keyFacts;

                    $record = [
                        'message_summaries'  => $messageSummaries,
                        'flagged_ranges'     => $flaggedRanges,
                        'detailed_summaries' => $detailedSummaries,
                        'enhanced_summary'   => $enhancedSummary,
                        'enhanced_key_facts' => $enhancedKeyFacts,
                        'proposed_tags'      => $conv->tags,
                        'enhanced_at'        => date('Y-m-d\TH:i:sP'),
                    ];

                    // --- Propose state updates ---
                    if (!$skipStateUpd) {
                        $diff = $enhancer->proposeStateUpdates($conv, $detailedSummaries);

                        if (!empty($diff)) {
                            $progressBar->clear();

                            $this->printProposal($io, $conv, $diff);

                            // Group A: summary + key_facts
                            $groupA = array_intersect_key($diff, array_flip(['summary', 'key_facts']));
                            if (!empty($groupA)) {
                                $acceptA = $autoYes || $io->confirm('Update summary / key_facts?', false);
                                if ($acceptA) {
                                    if (isset($diff['summary'])) {
                                        $conv->summary = $diff['summary']['new'];
                                        $record['enhanced_summary'] = $diff['summary']['new'];
                                    }
                                    if (isset($diff['key_facts'])) {
                                        $conv->keyFacts = $diff['key_facts']['new'];
                                        $record['enhanced_key_facts'] = $diff['key_facts']['new'];
                                    }
                                    $state->saveCategorisation($conv);
                                    $io->text('<info>✓ summary / key_facts updated in state.</info>');
                                }
                            }

                            // Group B: tags
                            if (isset($diff['tags'])) {
                                $acceptB = $autoYes || $io->confirm('Update tags?', false);
                                if ($acceptB) {
                                    $conv->tags = $diff['tags']['new'];
                                    $record['proposed_tags'] = $diff['tags']['new'];
                                    $state->saveCategorisation($conv);
                                    $io->text('<info>✓ tags updated in state.</info>');
                                }
                            }

                            $progressBar->display();
                        }
                    }

                    $enhancedStore->saveEnhanced($conv->id, $record);
                    $enhancedData[$conv->id] = $record;
                    $totalProcessed++;

                } catch (\Throwable $e) {
                    $progressBar->clear();
                    $io->warning("Error enhancing '{$conv->title}': {$e->getMessage()}");
                    $progressBar->display();
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);

            // --- Export enhanced context file ---
            $path = $exporter->export($slug, $catName, $catConvs, $enhancedData, $minRelevance);
            $exportedFiles[] = $path;
            $io->text("  <info>Exported:</info> {$path}");
        }

        $io->success(sprintf(
            'Enhancement complete. Processed: %d | Skipped (cached): %d | Files written: %d',
            $totalProcessed,
            $totalSkipped,
            count($exportedFiles),
        ));

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Display helpers
    // -----------------------------------------------------------------------

    private function printProposal(SymfonyStyle $io, Conversation $conv, array $diff): void
    {
        $io->section("[Proposal] {$conv->title}");

        if (isset($diff['summary'])) {
            $io->text('<comment>summary (current):</comment>  ' . $diff['summary']['old']);
            $io->text('<info>summary (proposed):</info> ' . $diff['summary']['new']);
            $io->newLine();
        }

        if (isset($diff['key_facts'])) {
            $io->text('<comment>key_facts (current):</comment>');
            foreach ($diff['key_facts']['old'] as $f) {
                $io->text("  - {$f}");
            }
            $io->text('<info>key_facts (proposed):</info>');
            foreach ($diff['key_facts']['new'] as $f) {
                $io->text("  + {$f}");
            }
            $io->newLine();
        }

        if (isset($diff['tags'])) {
            $io->text('<comment>tags (current):</comment>  ' . implode(', ', $diff['tags']['old']));
            $io->text('<info>tags (proposed):</info> ' . implode(', ', $diff['tags']['new']));
            $io->newLine();
        }
    }
}
