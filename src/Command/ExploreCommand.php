<?php

declare(strict_types=1);

namespace ChatGPTContext\Command;

use ChatGPTContext\Config\CategoryLoader;
use ChatGPTContext\Ollama\OllamaClient;
use ChatGPTContext\Parser\ChatGPTExportParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'explore', description: 'Discover conversation categories by letting the LLM suggest them')]
final class ExploreCommand extends Command
{
	protected function configure(): void
	{
		$this
			->addArgument('input', InputArgument::OPTIONAL, 'Path to a conversations.json file, or a directory (not needed with --apply-only)')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', './output')
			->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Ollama model to use (overrides config)')
			->addOption('host', null, InputOption::VALUE_REQUIRED, 'Ollama host URL', 'http://localhost:11434')
			->addOption('sample', 's', InputOption::VALUE_REQUIRED, 'Number of conversations to sample (0 = all)', '50')
			->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Minimum messages to include', '4')
			->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Conversations per LLM batch', '5')
			->addOption('max-chars', null, InputOption::VALUE_REQUIRED, 'Max chars of conversation text per conversation (lower = faster)', '600')
			->addOption('max-tokens', null, InputOption::VALUE_REQUIRED, 'Max tokens for LLM output (overrides config; increase for reasoning models)')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Interactively add discovered categories to categories.json')
			->addOption('free', null, InputOption::VALUE_NONE, 'Ignore predefined categories — let the LLM suggest a taxonomy from scratch')
			->addOption('apply-only', null, InputOption::VALUE_NONE, 'Skip exploration — go straight to the --apply review flow using the existing category-discovery.json');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io        = new SymfonyStyle($input, $output);
		$outputDir = $input->getOption('output');
		$freeMode  = (bool) $input->getOption('free');
		$applyOnly = (bool) $input->getOption('apply-only');
		$doApply   = (bool) $input->getOption('apply') || $applyOnly;

		$io->title('Category Explorer');

		$config     = require __DIR__ . '/../../config/config.php';
		$reportPath = rtrim($outputDir, '/') . '/category-discovery.json';

		// Set up Ollama
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

		// ------------------------------------------------------------------
		// Short-circuit: --apply-only
		// ------------------------------------------------------------------
		if ($applyOnly) {
			return $this->runFromReport($io, $config, $reportPath, $doApply);
		}

		// ------------------------------------------------------------------
		// Full exploration path requires an input argument
		// ------------------------------------------------------------------
		$inputPath = $input->getArgument('input');
		if ($inputPath === null) {
			$io->error('An input path (file or directory) is required unless you use --apply-only.');
			return Command::FAILURE;
		}

		$sampleSize  = (int) $input->getOption('sample');
		$minMessages = (int) $input->getOption('min-messages');
		$batchSize   = (int) $input->getOption('batch-size');
		$maxChars    = (int) $input->getOption('max-chars');

		// Auto-enable free mode when the user has no categories.json yet
		if (!$freeMode && !CategoryLoader::exists($config['categories_file'])) {
			$freeMode = true;
			$io->note(
				'No categories.json found — running in free mode so the LLM can discover a taxonomy from scratch. ' .
				'Use --apply to save discovered categories to categories.json.'
			);
		}

		if ($freeMode) {
			$existingSlugs = [];
			$existingList  = '';
			$io->note('Free mode: predefined categories are ignored — the LLM will suggest a fresh taxonomy.');
		} else {
			$existingSlugs = array_column(CategoryLoader::load($config['categories_file']), 'slug');
			$existingList  = implode(', ', $existingSlugs);
		}

		$ollama = OllamaClient::fromConfig($ollamaConfig);

		if (!$ollama->isAvailable()) {
			$io->error("Ollama not available (model: {$ollama->getModel()}). This command requires an LLM.");
			$models = $ollama->listModels();
			if (!empty($models)) {
				$io->text('Available models:');
				$io->listing($models);
			}
			return Command::FAILURE;
		}

		$io->success("Ollama connected — using model: {$ollama->getModel()}");

		// Parse conversations
		$parser        = new ChatGPTExportParser();
		try {
			$conversations = $parser->parseFromPath($inputPath);
		} catch (\RuntimeException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		$conversations = array_filter($conversations, fn($c) => $c->messageCount() >= $minMessages);
		$conversations = array_values($conversations);

		$io->text(sprintf('Loaded %d conversations (after min-message filter)', count($conversations)));

		// Stratified sample
		if ($sampleSize > 0 && $sampleSize < count($conversations)) {
			$step    = count($conversations) / $sampleSize;
			$sampled = [];
			for ($i = 0; $i < $sampleSize; $i++) {
				$idx       = (int) round($i * $step);
				$idx       = min($idx, count($conversations) - 1);
				$sampled[] = $conversations[$idx];
			}
			$conversations = $sampled;
			$io->note("Sampled {$sampleSize} conversations (stratified across time range)");
		}

		// ------------------------------------------------------------------
		// Process in batches, loading existing report first so results merge
		// ------------------------------------------------------------------
		$allSuggestions = $this->loadExistingDiscoveries($reportPath);
		$batches        = array_chunk($conversations, $batchSize);

		$io->progressStart(count($batches));

		foreach ($batches as $batch) {
			$suggestions = $this->exploreBatch($ollama, $batch, $existingList, $freeMode, $maxChars);
			foreach ($suggestions as $suggestion) {
				$slug = $suggestion['slug'] ?? '';
				if ($slug === '' || (!$freeMode && in_array($slug, $existingSlugs, true))) {
					continue;
				}

				if (!isset($allSuggestions[$slug])) {
					$allSuggestions[$slug] = [
						'slug'          => $slug,
						'name'          => $suggestion['name'] ?? $slug,
						'description'   => $suggestion['description'] ?? '',
						'sample_titles' => [],
						'count'         => 0,
					];
				}

				$allSuggestions[$slug]['count']++;
				$sampleTitles                           = $suggestion['sample_titles'] ?? [];
				$allSuggestions[$slug]['sample_titles'] = array_unique(
					array_merge($allSuggestions[$slug]['sample_titles'], $sampleTitles)
				);

				if (mb_strlen($suggestion['description'] ?? '') > mb_strlen($allSuggestions[$slug]['description'])) {
					$allSuggestions[$slug]['description'] = $suggestion['description'];
				}
			}

			$io->progressAdvance();
		}

		$io->progressFinish();

		if (empty($allSuggestions)) {
			$io->success('No new categories discovered — existing categories appear to cover everything.');
			return Command::SUCCESS;
		}


		uasort($allSuggestions, fn($a, $b) => $b['count'] <=> $a['count']);

		$this->printDiscoveryTable($io, $allSuggestions);
		$this->saveDiscoveryReport($reportPath, $allSuggestions, $ollama->getModel(), $freeMode, count($conversations), $existingSlugs);

		$io->text("Discovery report saved to: {$reportPath}");

		if ($doApply) {
			$this->runApply($io, $config, $allSuggestions, $existingSlugs);
		}

		return Command::SUCCESS;
	}

	// -----------------------------------------------------------------------
	// Short-circuit: load from existing report
	// -----------------------------------------------------------------------

	private function runFromReport(
		SymfonyStyle $io,
		array $config,
		string $reportPath,
		bool $doApply,
	): int {
		if (!file_exists($reportPath)) {
			$io->error("No discovery report found at: {$reportPath}. Run explore first to generate one.");
			return Command::FAILURE;
		}

		$reportData    = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
		$existingSlugs = $reportData['existing_categories'] ?? [];

		// Rebuild allSuggestions map from the report's discovered_categories array
		$allSuggestions = [];
		foreach ($reportData['discovered_categories'] ?? [] as $cat) {
			$slug = $cat['slug'] ?? '';
			if ($slug !== '') {
				$allSuggestions[$slug] = $cat + ['count' => $cat['count'] ?? 1];
			}
		}

		if (empty($allSuggestions)) {
			$io->warning('The discovery report contains no categories.');
			return Command::SUCCESS;
		}

		$io->text(sprintf('Loaded %d categories from %s', count($allSuggestions), $reportPath));
		$this->printDiscoveryTable($io, $allSuggestions);

		if ($doApply) {
			$this->runApply($io, $config, $allSuggestions, $existingSlugs);
		}

		return Command::SUCCESS;
	}

	// -----------------------------------------------------------------------
	// Interactive apply flow
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, array{slug: string, name: string, description: string, sample_titles: array<string>, count: int}> $allSuggestions
	 * @param array<string> $existingSlugs slugs already in categories.json
	 */
	private function runApply(SymfonyStyle $io, array $config, array $allSuggestions, array $existingSlugs): void
	{
		$io->section('Apply Discovered Categories');
		$io->text('Review each suggestion and decide whether to add it to categories.json.');
		$io->newLine();

		// Merge targets = existing categories.json slugs PLUS anything added so far this session
		$mergeSlugs = $existingSlugs;
		$toAdd      = [];

		foreach ($allSuggestions as $suggestion) {
			$io->text(sprintf(
				'<info>%s</info> (%s) — mentioned %d time(s)',
				$suggestion['name'],
				$suggestion['slug'],
				$suggestion['count'],
			));
			$io->text("  Description: {$suggestion['description']}");

			if (!empty($suggestion['sample_titles'])) {
				$io->text('  Sample conversations: ' . implode('; ', array_slice($suggestion['sample_titles'], 0, 3)));
			}

			// Loop so the user can go back if they change their mind
			do {
				$choices = ['add' => 'Add as new category', 'merge' => 'Merge into existing category', 'skip' => 'Skip', 'done' => 'Done reviewing'];
				$action  = $io->choice('Action', $choices, 'skip');

				if ($action === 'done') {
					break 2; // break out of foreach too
				}

				if ($action === 'add') {
					$keywords = $io->ask(
						'Keywords (comma-separated)',
						implode(', ', $this->generateKeywords($suggestion)),
					);

					$entry = [
						'slug'        => $suggestion['slug'],
						'name'        => $suggestion['name'],
						'description' => $suggestion['description'],
						'keywords'    => array_map('trim', explode(',', (string) $keywords)),
					];

					$toAdd[]      = $entry;
					$mergeSlugs[] = $suggestion['slug']; // now available as a merge target

					// Save immediately so the user can merge later entries into this one
					try {
						CategoryLoader::save($config['categories_file'], [$entry]);
					} catch (\RuntimeException $e) {
						$io->error("Failed to save: {$e->getMessage()}");
					}
				}

				if ($action === 'merge') {
					if (empty($mergeSlugs)) {
						$io->warning('No categories available as merge targets yet. Add at least one category first, or merge into an existing categories.json entry.');
						$action = 'back'; // force re-prompt
						continue;
					}

					$targetSlug = $io->choice('Merge into which category?', $mergeSlugs);

					// Append keywords of the source suggestion to the target
					$extraKeywords = $this->generateKeywords($suggestion);
					if (!empty($extraKeywords)) {
						try {
							$existing = CategoryLoader::load($config['categories_file']);
							foreach ($existing as &$cat) {
								if ($cat['slug'] === $targetSlug) {
									$cat['keywords'] = array_values(array_unique(
										array_merge($cat['keywords'] ?? [], $extraKeywords)
									));
									break;
								}
							}
							unset($cat);
							CategoryLoader::save($config['categories_file'], $existing);
							$io->text("  ✓ Keywords from '{$suggestion['slug']}' appended to '{$targetSlug}'.");
						} catch (\RuntimeException $e) {
							$io->error("Failed to append keywords: {$e->getMessage()}");
						}
					}
				}
			} while ($action === 'back');
		}

		$count = count($toAdd);
		if ($count > 0) {
			$io->success(sprintf('%d %s added to categories.json.', $count, $count === 1 ? 'category' : 'categories'));
		} else {
			$io->text('No new categories added.');
		}
	}

	// -----------------------------------------------------------------------
	// Report helpers
	// -----------------------------------------------------------------------

	/**
	 * Load existing discovered_categories from a report file (if present),
	 * keyed by slug. Returns empty array if file doesn't exist.
	 *
	 * @return array<string, array{slug: string, name: string, description: string, sample_titles: array<string>, count: int}>
	 */
	private function loadExistingDiscoveries(string $reportPath): array
	{
		if (!file_exists($reportPath)) {
			return [];
		}

		try {
			$data = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return []; // corrupted report — start fresh
		}

		$bySlug = [];
		foreach ($data['discovered_categories'] ?? [] as $cat) {
			$slug = $cat['slug'] ?? '';
			if ($slug !== '') {
				$bySlug[$slug] = $cat + ['count' => $cat['count'] ?? 1];
			}
		}

		return $bySlug;
	}

	/**
	 * @param array<string, array{slug: string, name: string, description: string, sample_titles: array<string>, count: int}> $allSuggestions
	 * @param array<string> $existingSlugs
	 */
	private function saveDiscoveryReport(
		string $reportPath,
		array $allSuggestions,
		string $model,
		bool $freeMode,
		int $conversationsSampled,
		array $existingSlugs,
	): void {
		$dir = dirname($reportPath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents(
			$reportPath,
			json_encode([
				'discovered_at'         => date('Y-m-d\TH:i:sP'),
				'model'                 => $model,
				'free_mode'             => $freeMode,
				'conversations_sampled' => $conversationsSampled,
				'existing_categories'   => $existingSlugs,
				'discovered_categories' => array_values($allSuggestions),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		);
	}

	/**
	 * @param array<string, array{slug: string, name: string, description: string, sample_titles: array<string>, count: int}> $allSuggestions
	 */
	private function printDiscoveryTable(SymfonyStyle $io, array $allSuggestions): void
	{
		$io->section('Discovered Categories');
		$io->text(sprintf('Found %d potential new categories:', count($allSuggestions)));
		$io->newLine();

		$rows = [];
		foreach ($allSuggestions as $suggestion) {
			$samples = array_slice($suggestion['sample_titles'], 0, 3);
			$rows[]  = [
				$suggestion['slug'],
				$suggestion['name'],
				(string) ($suggestion['count'] ?? 1),
				implode('; ', $samples),
			];
		}

		$io->table(['Slug', 'Name', 'Mentions', 'Sample Conversations'], $rows);
	}


	private function buildConstrainedSystemPrompt(string $existingCategories): string
	{
		return <<<SYSTEM
You are analysing a batch of conversations to discover what topics they cover.

The user already has these categories defined: {$existingCategories}

Your job is to identify topics that do NOT fit well into any of those existing categories.
If a conversation fits an existing category, ignore it. Only suggest NEW categories for
conversations that cover genuinely different ground.

Respond ONLY with valid JSON (no markdown fences, no preamble). Use this structure:
{
  "suggestions": [
    {
      "slug": "kebab-case-slug",
      "name": "Human-Readable Category Name",
      "description": "What this category covers and why it's distinct from existing ones",
      "sample_titles": ["Title of conversation that belongs here"]
    }
  ]
}

Rules:
- Only suggest categories that are meaningfully distinct from the existing ones.
- If all conversations fit existing categories, return {"suggestions": []}.
- Slugs must be kebab-case, max 30 characters.
- Be specific — "misc-technical" is too vague. "embedded-rust-hardware" is good.
- Merge similar suggestions into one category rather than creating many overlapping ones.
- Include 1-3 sample conversation titles per suggestion.
SYSTEM;
	}

	private function buildFreeSystemPrompt(): string
	{
		return <<<'SYSTEM'
You are analysing a batch of conversations and building a taxonomy from scratch.
There are no predefined categories — your job is to identify the most natural,
useful groupings for the conversations you see.

Respond ONLY with valid JSON (no markdown fences, no preamble). Use this structure:
{
  "suggestions": [
    {
      "slug": "kebab-case-slug",
      "name": "Human-Readable Category Name",
      "description": "What this category covers and what makes it a coherent group",
      "sample_titles": ["Title of conversation that belongs here"]
    }
  ]
}

Rules:
- Suggest whichever categories best describe the conversations — do not hold back.
- If a batch of conversations all fall into one clear group, one suggestion is fine.
- Slugs must be kebab-case, max 30 characters.
- Be specific — "misc-technical" is too vague. "embedded-rust-hardware" is good.
- Merge similar suggestions into one category rather than creating many overlapping ones.
- Include 1-3 sample conversation titles per suggestion.
- Do not suggest a catch-all "other" or "miscellaneous" category.
SYSTEM;
	}

	// -----------------------------------------------------------------------
	// LLM calls
	// -----------------------------------------------------------------------

	/**
	 * @param array<\ChatGPTContext\Parser\Conversation> $batch
	 * @return array<array{slug: string, name: string, description: string, sample_titles: array<string>}>
	 */
	private function exploreBatch(
		OllamaClient $ollama,
		array $batch,
		string $existingCategories,
		bool $freeMode = false,
		int $maxChars = 600,
	): array {
		$conversationSummaries = [];
		foreach ($batch as $conv) {
			$condensed               = $conv->toCondensedText($maxChars);
			$conversationSummaries[] = "--- CONVERSATION: \"{$conv->title}\" ({$conv->createDate()}) ---\n{$condensed}";
		}

		$batchText = implode("\n\n", $conversationSummaries);
		$system    = $freeMode
			? $this->buildFreeSystemPrompt()
			: $this->buildConstrainedSystemPrompt($existingCategories);

		$response = '';
		try {
			$response = $ollama->generate($batchText, $system, jsonMode: true);
			$json     = $this->extractJson($response);
			$result   = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
			return $result['suggestions'] ?? [];
		} catch (\Throwable $e) {
			$snippet = mb_substr($response, 0, 1500);
			error_log("Explore batch failed: {$e->getMessage()} | Raw response (first 1500 chars): {$snippet}");
			return [];
		}
	}
	
	// -----------------------------------------------------------------------
	// JSON extraction
	// -----------------------------------------------------------------------

	/**
	 * Extract the first complete JSON object from a raw LLM response.
	 *
	 * @throws \RuntimeException if no JSON object can be found
	 */
	private function extractJson(string $raw): string
	{
		$cleaned = preg_replace('/```(?:json)?\s*(.*?)\s*```/si', '$1', $raw) ?? $raw;

		$start = strpos($cleaned, '{');
		if ($start === false) {
			throw new \RuntimeException('No JSON object found in model response');
		}

		$depth  = 0;
		$length = strlen($cleaned);
		$inStr  = false;
		$escape = false;

		for ($i = $start; $i < $length; $i++) {
			$ch = $cleaned[$i];

			if ($escape) {
				$escape = false;
				continue;
			}
			if ($ch === '\\' && $inStr) {
				$escape = true;
				continue;
			}
			if ($ch === '"') {
				$inStr = !$inStr;
				continue;
			}
			if ($inStr) {
				continue;
			}

			if ($ch === '{') {
				$depth++;
			} elseif ($ch === '}') {
				$depth--;
				if ($depth === 0) {
					return substr($cleaned, $start, (int) ($i - $start + 1));
				}
			}
		}

		throw new \RuntimeException('Unbalanced JSON object in model response');
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/** @return array<string> */
	private function generateKeywords(array $suggestion): array
	{
		$words     = preg_split('/[\s\-_]+/', mb_strtolower($suggestion['name']));
		$stopWords = ['and', 'the', 'of', 'in', 'for', 'a', 'an', 'to', 'with', '&', '/'];
		$keywords  = array_filter($words, fn($w) => !in_array($w, $stopWords, true) && mb_strlen($w) > 2);

		$slugParts = explode('-', $suggestion['slug']);
		$keywords  = array_merge($keywords, array_filter($slugParts, fn($w) => mb_strlen($w) > 2));

		return array_values(array_unique($keywords));
	}
}