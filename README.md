# ChatGPT Context Tool

CLI tool to parse, categorise, and package ChatGPT exports for LLM context consumption. Built for processing 500+ conversation exports into structured, reviewable context packages.

> [!NOTE]
> This tool uses large language models (LLMs) via Ollama for categorisation and category discovery. **LLMs are non-deterministic** — the same input can produce different output on different runs, and the model may occasionally return malformed JSON, miss categories, or produce unexpected results. This is normal. Commands are designed to be **safely re-run**: state is persisted between runs so you won't lose work, and results are merged rather than overwritten. If a batch fails or the output looks off, just run the command again.

## Requirements

- PHP 8.4+ (tested on PHP 8.5)
- Composer
- Ollama (optional but recommended for semantic categorisation)

## Installation

```bash
cd chatgpt-context-tool
composer install
chmod +x bin/ctx
```

## Configuration

Copy `.env.example` to `.env` and adjust as needed. You can also override most settings via CLI flags.

### Categories

The tool needs to know what categories to organise your conversations into. These are stored in a `categories.json` file in the project root (this file is `.gitignored` so your personal taxonomy never ends up in source control).

To get started, copy the example and customise it:

```bash
cp categories.json.example categories.json
```

Each entry in `categories.json` has a **slug**, **name**, **description** (used in the LLM prompt), and **keywords** (for keyword-only fallback):

```json
[
  {
    "slug": "software-dev",
    "name": "Software Development",
    "description": "Programming, debugging, architecture, code reviews, CI/CD, and tooling.",
    "keywords": ["code", "api", "debug", "deploy", "git"]
  }
]
```

If you have no idea what categories you need yet, skip creating `categories.json` and run `explore` first — with no file present it automatically enters free mode and lets the LLM discover a taxonomy from your conversations.

Ollama connection settings and processing parameters live in `config/config.php` and can be overridden via `.env` variables.

### Ollama Model Selection

The tool is model-agnostic. Recommended choices:

| Model | Best for | Notes |
|-------|----------|-------|
| `deepseek-r1` | Highest quality categorisation | Slower, strong reasoning |
| `qwen3` | Good balance of speed/quality | Works well on Linux |
| `qwen3.5` | macOS-friendly alternative | Good general performance |
| `atla/selene-mini` | Fast iteration, testing | Lightweight |

Override at runtime: `./bin/ctx categorise conversations.json -m qwen3`

## Workflow

The recommended workflow is: **parse → explore → (adjust config) → categorise → review → export → stats**

> **Tip:** Every command that takes a conversations export accepts either a single `conversations.json` file **or** a directory path — when a directory is given, all `*.json` files inside are merged and deduplicated automatically. The examples throughout this section show both forms interchangeably.

### 1. Parse the export

```bash
# Single file
./bin/ctx parse /path/to/conversations.json

# Whole directory of exports
./bin/ctx parse input/
```

Reads the ChatGPT export, filters out trivially short conversations, and creates a `parsed-index.json`.

### 2. Explore categories

Before categorising, let the LLM discover what topics actually exist in your conversations. This avoids forcing everything into pre-defined categories and missing important clusters.

```bash
# Point at a single file or a whole directory of *.json exports
./bin/ctx explore input/
./bin/ctx explore /path/to/conversations.json

# Free mode: let the LLM build a taxonomy from scratch (no predefined categories)
./bin/ctx explore input/ --free

# Sample more conversations for coverage (-s 0 = all)
./bin/ctx explore input/ -s 100

# Re-run after a partial failure — results are MERGED into the existing report
./bin/ctx explore input/

# Explore and immediately review results in a single pass
./bin/ctx explore input/ --apply

# Load an existing discovery report and review (no re-exploration)
./bin/ctx explore --apply-only

# Tune for speed on long conversations
./bin/ctx explore input/ --max-chars 400

# Tune for reasoning models
./bin/ctx explore input/ --max-tokens 8192
```

The explorer uses stratified sampling across the full time range, batches conversations for efficient LLM calls, and aggregates suggestions — if a topic surfaces repeatedly it gets a high count, indicating a real cluster rather than noise.

Each run **merges** its results into `output/category-discovery.json` rather than overwriting it, so you can safely re-run after partial failures to pick up missed batches.

With `--apply`, you can interactively review each discovered category:
- **Add** — add it to `categories.json` (immediately saved; also becomes a merge target for later items in the same session)
- **Merge** — pick a target category; the source's keywords are automatically appended to the target's keyword list in `categories.json`
- **Skip** — ignore for now
- **Back** — revisit the previous decision
- **Done** — stop reviewing early

A discovery report is always saved to `output/category-discovery.json`.

#### Recommended settings for reasoning models (deepseek-r1, qwen)

Reasoning models emit a lengthy `<think>` block before producing output. With long conversations this can exhaust the token budget before any JSON appears.

```
--batch-size 3 --max-chars 600 --max-tokens 4096
```

Set `OLLAMA_TIMEOUT` in `.env` to at least `300` (seconds) per batch.

### 3. Categorise conversations

```bash
# With Ollama (recommended)
./bin/ctx categorise /path/to/conversations.json
./bin/ctx categorise input/

# With a specific model
./bin/ctx categorise /path/to/conversations.json -m qwen3

# Keyword-only (no Ollama needed)
./bin/ctx categorise /path/to/conversations.json --keywords-only

# Test with a small batch first
./bin/ctx categorise /path/to/conversations.json -l 10

# Increase token budget for long conversations or reasoning models
./bin/ctx categorise input/ --max-tokens 4096

# Debug persistent failures — prints the raw model response for any conversation that errors
./bin/ctx categorise input/ --debug 2>debug.log
```

Categorisation state is persisted — if interrupted, re-running will resume from where it left off, skipping conversations that were already successfully categorised. Conversations where the LLM previously failed (empty summary and tags) are **automatically retried** on the next run. Use `--reset` to start completely fresh.

### 4. Review and correct

```bash
# Review all categorised conversations
./bin/ctx review /path/to/conversations.json
./bin/ctx review input/

# Review a specific category
./bin/ctx review /path/to/conversations.json -c software-dev

# Review only high-relevance conversations
./bin/ctx review /path/to/conversations.json --min-relevance 0.7

# Review specific conversations by ID (repeatable or comma-separated)
./bin/ctx review input/ --id 68f10039-e010-832b-9a54-63eccdae57c3
./bin/ctx review input/ --id abc123 --id def456
./bin/ctx review input/ --id abc123,def456
```

Each conversation is presented with its metadata, summary, and key facts. You can take **multiple actions** before moving on — the prompt loops until you choose **next**:

- **next** — advance to the next conversation (saves all changes made in this iteration)
- **recategorise** — shows a numbered list of all categories (with current ones marked `*`); enter comma-separated numbers to reassign
- **relevance** — set a new relevance score (0.0–1.0)
- **delete** — set relevance to 0 (marks as irrelevant)
- **quit** — save changes to the current conversation and exit

All changes within a single conversation are accumulated and written once when you advance, so you can recategorise *and* adjust relevance in the same step.

### 5. Export

```bash
# Standard export (JSON + Markdown per category)
./bin/ctx export /path/to/conversations.json
./bin/ctx export input/

# Export only one category
./bin/ctx export /path/to/conversations.json -c software-dev

# Generate LLM-optimised context packages
./bin/ctx export /path/to/conversations.json --context-package

# Context package with minimum relevance threshold
./bin/ctx export /path/to/conversations.json --context-package --min-relevance 0.5 -c software-dev
```

### 6. View statistics

```bash
./bin/ctx stats /path/to/conversations.json
./bin/ctx stats input/
```

## Output Structure

```
output/
├── index.json                    # Master index with category stats
├── parsed-index.json             # Raw parse results
├── category-discovery.json       # Explore results (discovered categories)
├── .ctx-state.json               # Processing state (for resume)
├── software-dev.json             # Category: JSON format
├── software-dev.md               # Category: Markdown for review
├── creative-writing.json
├── creative-writing.md
├── context-software-dev.md       # LLM context package (--context-package)
└── ...
```

### Context Packages

The `--context-package` flag generates markdown files optimised for pasting into an LLM conversation. These contain:

- Conversation summaries sorted by relevance
- Key facts and decisions extracted from each conversation
- Tags for quick scanning
- No raw message content (just the distilled context)

These are designed to be reviewed by you, corrected if needed, and then fed to Claude as context for continuity.

## Categories

Categories are defined in your local `categories.json` file (not tracked by git). See `categories.json.example` for the schema. Use `./bin/ctx explore --free --apply` to let the LLM discover a starting taxonomy for you.