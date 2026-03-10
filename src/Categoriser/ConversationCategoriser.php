<?php

declare(strict_types=1);

namespace ChatGPTContext\Categoriser;

use ChatGPTContext\Ollama\LlmResponse;
use ChatGPTContext\Ollama\OllamaClient;
use ChatGPTContext\Parser\Conversation;

final class ConversationCategoriser
{
    /** @var array<array{slug: string, name: string, description: string, keywords: array<string>}> */
    private array $categories;

    public function __construct(
        private readonly ?OllamaClient $ollama,
        array $categories,
        private readonly int $maxContextChars = 8000,
        private readonly bool $debug = false,
    ) {
        $this->categories = $categories;
    }

    /**
     * Categorise a single conversation using LLM if available, keyword fallback otherwise.
     */
    public function categorise(Conversation $conversation): Conversation
    {
        if ($this->ollama !== null) {
            return $this->categoriseWithLlm($conversation);
        }

        return $this->categoriseWithKeywords($conversation);
    }

    /**
     * Use Ollama for semantic categorisation + summary + key fact extraction.
     */
    private function categoriseWithLlm(Conversation $conversation): Conversation
    {
        $categoryList = array_map(
            fn(array $cat) => "- **{$cat['slug']}**: {$cat['description']}",
            $this->categories,
        );
        $categoryText = implode("\n", $categoryList);

        $system = <<<SYSTEM
You are a conversation analyser. Your task is to read a conversation transcript and produce a structured JSON analysis of it.
Do NOT continue the conversation. Do NOT respond as a participant. Analyse it from the outside.

IMPORTANT: Always respond in English, regardless of what language the conversation is in.

Available categories:
{$categoryText}

Respond ONLY with valid JSON (no markdown fences, no preamble). Use this exact structure:
{
  "categories": ["slug1", "slug2"],
  "tags": ["specific-topic-tag", "another-tag"],
  "summary": "2-3 sentence summary of the conversation's substance",
  "key_facts": ["Concrete fact or decision 1", "Concrete fact or decision 2"],
  "relevance_score": 0.85
}

Rules:
- Assign 1-3 categories. Use "other" only if nothing else fits.
- Tags should be specific topics discussed (e.g., "react-hooks", "database-migration", "api-auth").
- Summary should focus on WHAT was discussed and DECIDED, not meta-commentary.
- key_facts should capture concrete decisions, preferences, facts, or plans — things worth remembering.
- relevance_score: 0.0 = trivial/throwaway, 1.0 = extremely important context. Consider how useful this conversation would be for maintaining continuity with the user.
SYSTEM;

        $condensed = $conversation->toCondensedText($this->maxContextChars);

        $prompt = <<<PROMPT
CONVERSATION TO ANALYSE:
---
{$condensed}
---

Now provide your JSON analysis of the above conversation. Remember: respond ONLY with valid JSON, no other text.
PROMPT;

        try {
            $response = $this->ollama->generate($prompt, $system);

            // Strip any markdown fences or thinking tags that models like deepseek-r1 emit
            $response = preg_replace('/^```(?:json)?\s*/m', '', $response);
            $response = preg_replace('/```\s*$/m', '', $response);
            $response = preg_replace('/<think>.*?<\/think>/s', '', $response);
            $response = trim($response);

            $result = json_decode(LlmResponse::extractJson($response), true, 512, JSON_THROW_ON_ERROR);

            $conversation->categories = $this->validateCategories($result['categories'] ?? []);
            $conversation->tags = array_slice($result['tags'] ?? [], 0, 10);
            $conversation->summary = $result['summary'] ?? '';
            $conversation->keyFacts = $result['key_facts'] ?? [];
            $conversation->relevanceScore = max(0.0, min(1.0, (float) ($result['relevance_score'] ?? 0.5)));

        } catch (\Throwable $e) {
            if ($this->debug) {
                fwrite(STDERR, "\n[DEBUG] Raw model response for '{$conversation->title}':\n{$response}\n");
            }
            // Re-throw so the command layer can record the error and leave this
            // conversation unsaved — it will be retried on the next run.
            throw $e;
        }

        return $conversation;
    }

    /**
     * Fallback: match categories by keyword presence in title + messages.
     */
    private function categoriseWithKeywords(Conversation $conversation): Conversation
    {
        $haystack = mb_strtolower($conversation->title . ' ' . $this->extractSearchableText($conversation));
        $matched = [];

        foreach ($this->categories as $category) {
            if ($category['slug'] === 'other') {
                continue;
            }

            foreach ($category['keywords'] as $keyword) {
                if (str_contains($haystack, mb_strtolower($keyword))) {
                    $matched[] = $category['slug'];
                    break;
                }
            }
        }

        if (empty($matched)) {
            $matched = ['other'];
        }

        $conversation->categories = $matched;
        $conversation->relevanceScore = 0.5; // Default for keyword-only categorisation

        return $conversation;
    }

    /**
     * Extract searchable text from first N user messages.
     */
    private function extractSearchableText(Conversation $conversation): string
    {
        $texts = [];
        $count = 0;

        foreach ($conversation->messages as $msg) {
            if ($msg->role === 'user') {
                $texts[] = $msg->content;
                $count++;
                if ($count >= 5) {
                    break;
                }
            }
        }

        return implode(' ', $texts);
    }

    /**
     * Validate category slugs against the configured categories.
     *
     * @param array<string> $slugs
     * @return array<string>
     */
    private function validateCategories(array $slugs): array
    {
        $validSlugs = array_column($this->categories, 'slug');

        $validated = array_filter($slugs, fn(string $s) => in_array($s, $validSlugs, true));

        return empty($validated) ? ['other'] : array_values($validated);
    }
}