<?php

declare(strict_types=1);

namespace ChatGPTContext\Command;

use ChatGPTContext\Parser\ChatGPTExportParser;
use ChatGPTContext\Parser\StateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'stats', description: 'Show statistics about parsed/categorised conversations')]
final class StatsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'Path to a conversations.json file, or a directory of *.json exports')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', './output')
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Minimum messages to include', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('input');
        $outputDir = $input->getOption('output');
        $minMessages = (int) $input->getOption('min-messages');

        $io->title('Conversation Statistics');

        $parser = new ChatGPTExportParser();
        $conversations = $parser->parseFromPath($filePath);
        $allCount = count($conversations);
        $conversations = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
        $conversations = array_values($conversations);

        $state = new StateStore($outputDir);

        // Restore categorisations
        $categorisedCount = 0;
        foreach ($conversations as $conv) {
            if ($state->isCategorised($conv->id)) {
                $cached = $state->getCategorisation($conv->id);
                $conv->categories = $cached['categories'] ?? [];
                $conv->relevanceScore = (float) ($cached['relevance_score'] ?? 0);
                $categorisedCount++;
            }
        }

        // General stats
        $totalMessages = array_sum(array_map(fn($c) => $c->messageCount(), $conversations));

        $io->table(
            ['Metric', 'Value'],
            [
                ['Total conversations (raw)', (string) $allCount],
                ['After min-message filter', (string) count($conversations)],
                ['Categorised', (string) $categorisedCount],
                ['Uncategorised', (string) (count($conversations) - $categorisedCount)],
                ['Total messages', (string) $totalMessages],
                ['Avg messages/conversation', (string) round($totalMessages / max(1, count($conversations)))],
            ],
        );

        if ($categorisedCount > 0) {
            // Category breakdown
            $catCounts = [];
            $catRelevance = [];

            foreach ($conversations as $conv) {
                foreach ($conv->categories as $cat) {
                    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
                    $catRelevance[$cat][] = $conv->relevanceScore;
                }
            }

            arsort($catCounts);

            $rows = [];
            foreach ($catCounts as $slug => $count) {
                $scores = $catRelevance[$slug];
                $avgRelevance = round(array_sum($scores) / count($scores), 2);
                $highRelevance = count(array_filter($scores, fn($s) => $s >= 0.7));
                $rows[] = [$slug, (string) $count, (string) $avgRelevance, (string) $highRelevance];
            }

            $io->section('Category Breakdown');
            $io->table(
                ['Category', 'Conversations', 'Avg Relevance', 'High Relevance (≥0.7)'],
                $rows,
            );

            // Relevance distribution
            $allScores = array_filter(array_map(fn($c) => $c->relevanceScore, $conversations), fn($s) => $s > 0);
            if (!empty($allScores)) {
                $buckets = [
                    '0.0-0.2' => 0, '0.2-0.4' => 0, '0.4-0.6' => 0,
                    '0.6-0.8' => 0, '0.8-1.0' => 0,
                ];

                foreach ($allScores as $score) {
                    match (true) {
                        $score < 0.2 => $buckets['0.0-0.2']++,
                        $score < 0.4 => $buckets['0.2-0.4']++,
                        $score < 0.6 => $buckets['0.4-0.6']++,
                        $score < 0.8 => $buckets['0.6-0.8']++,
                        default => $buckets['0.8-1.0']++,
                    };
                }

                $io->section('Relevance Distribution');
                $io->table(
                    ['Score Range', 'Count'],
                    array_map(fn($k, $v) => [$k, (string) $v], array_keys($buckets), array_values($buckets)),
                );
            }
        }

        return Command::SUCCESS;
    }
}