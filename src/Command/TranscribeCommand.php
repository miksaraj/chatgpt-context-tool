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

#[AsCommand(name: 'transcribe', description: 'Render a single conversation as a human-readable Markdown file')]
final class TranscribeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'Path to a conversations.json file, or a directory of *.json exports')
            ->addOption('conv', 'c', InputOption::VALUE_REQUIRED, 'ID of the conversation to transcribe')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', './output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath  = $input->getArgument('input');
        $convId    = $input->getOption('conv');
        $outputDir = $input->getOption('output');

        $io->title('ChatGPT Conversation Transcriber');

        if ($convId === null || $convId === '') {
            $io->error('You must provide a conversation ID via --conv (-c).');
            return Command::FAILURE;
        }

        $io->text("Parsing: {$filePath}");

        $parser = new ChatGPTExportParser();

        try {
            $conversations = $parser->parseFromPath($filePath);
        } catch (\Throwable $e) {
            $io->error("Parse failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Find the requested conversation
        $found = null;
        foreach ($conversations as $conv) {
            if ($conv->id === $convId) {
                $found = $conv;
                break;
            }
        }

        if ($found === null) {
            $io->error("Conversation not found: {$convId}");
            $io->note(sprintf('%d conversations were parsed from the input — check the ID is correct.', count($conversations)));
            return Command::FAILURE;
        }

        // Ensure output sub-directory exists
        $transcriptsDir = rtrim($outputDir, '/') . '/conversations';
        if (!is_dir($transcriptsDir)) {
            mkdir($transcriptsDir, 0755, true);
        }

        // Build a safe filename from the title (fallback: conversation ID)
        $safeName = $this->sanitiseFilename($found->title) ?: $found->id;
        $outPath  = "{$transcriptsDir}/{$safeName}.md";

        $markdown = $found->toMarkdown();

        if (file_put_contents($outPath, $markdown) === false) {
            $io->error("Could not write file: {$outPath}");
            return Command::FAILURE;
        }

        $io->success("Transcript written to: {$outPath}");
        $io->table(
            ['Property', 'Value'],
            [
                ['Title',    $found->title],
                ['Created',  $found->createDate()],
                ['Messages', (string) $found->messageCount()],
                ['File',     $outPath],
            ],
        );

        return Command::SUCCESS;
    }

    /**
     * Strip characters that are unsafe in filenames and collapse whitespace.
     * Returns an empty string if nothing usable remains.
     */
    private function sanitiseFilename(string $title): string
    {
        // Replace non-alphanumeric (except spaces and hyphens) with nothing
        $safe = preg_replace('/[^\w\s\-]/u', '', $title) ?? '';
        // Collapse whitespace to underscores
        $safe = preg_replace('/\s+/', '_', trim($safe)) ?? '';
        // Truncate to keep filenames sane
        return mb_substr($safe, 0, 80);
    }
}
