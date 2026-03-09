<?php

declare(strict_types=1);

namespace ChatGPTContext\Command;

use ChatGPTContext\Parser\ChatGPTExportParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'parse', description: 'Parse a ChatGPT export file and build a conversation index')]
final class ParseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'Path to conversations.json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', './output')
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Minimum messages to include', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('input');
        $outputDir = $input->getOption('output');
        $minMessages = (int) $input->getOption('min-messages');

        $io->title('ChatGPT Export Parser');

        if (!file_exists($filePath)) {
            $io->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        $io->text("Parsing: {$filePath}");

        $parser = new ChatGPTExportParser();

        try {
            $conversations = $parser->parse($filePath);
        } catch (\Throwable $e) {
            $io->error("Parse failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $io->success(sprintf('Parsed %d conversations', count($conversations)));

        // Filter by min messages
        $filtered = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
        $skipped = count($conversations) - count($filtered);

        if ($skipped > 0) {
            $io->note("Skipped {$skipped} conversations with fewer than {$minMessages} messages");
        }

        $io->text(sprintf('Conversations to process: %d', count($filtered)));

        // Save parsed index – merge with any existing index so multiple
        // input files accumulate into one index rather than overwriting it.
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $indexPath = "{$outputDir}/parsed-index.json";

        // Load existing conversations keyed by ID (if the index already exists)
        $existingById = [];
        if (file_exists($indexPath)) {
            try {
                $existing = json_decode(
                    file_get_contents($indexPath) ?: '{}',
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                foreach ($existing['conversations'] ?? [] as $conv) {
                    $existingById[$conv['id']] = $conv;
                }
            } catch (\JsonException) {
                // Corrupted index – start fresh
            }
        }

        // Merge: newly parsed conversations overwrite any existing entry with the same ID
        $newById = [];
        foreach ($filtered as $c) {
            $newById[$c->id] = [
                'id'            => $c->id,
                'title'         => $c->title,
                'created'       => $c->createDate(),
                'updated'       => $c->updateDate(),
                'messages'      => $c->messageCount(),
                'user_messages' => $c->userMessageCount(),
            ];
        }

        $mergedById = array_merge($existingById, $newById);

        // Sort combined list by created date, newest first
        uasort($mergedById, fn($a, $b) => strcmp($b['created'], $a['created']));

        $added   = count(array_diff_key($newById, $existingById));
        $updated = count(array_intersect_key($newById, $existingById));

        $indexData = [
            'parsed_at'              => date('Y-m-d\\TH:i:sP'),
            'total_conversations'    => count($mergedById),
            'min_messages'           => $minMessages,
            'conversations'          => array_values($mergedById),
        ];

        file_put_contents(
            $indexPath,
            json_encode($indexData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        if ($added > 0 || $updated > 0) {
            $io->success(sprintf(
                'Index updated: %d added, %d updated → %d total (saved to %s)',
                $added,
                $updated,
                count($mergedById),
                $indexPath,
            ));
        } else {
            $io->success("Index saved to {$indexPath}");
        }

        // Show date range
        if (!empty($filtered)) {
            $filtered = array_values($filtered);
            $oldest = end($filtered);
            $newest = reset($filtered);
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total conversations', (string) count($conversations)],
                    ['After filtering', (string) count($filtered)],
                    ['Oldest', $oldest->createDate()],
                    ['Newest', $newest->createDate()],
                    ['Average messages', (string) round(array_sum(array_map(fn($c) => $c->messageCount(), $filtered)) / count($filtered))],
                ],
            );
        }

        return Command::SUCCESS;
    }
}