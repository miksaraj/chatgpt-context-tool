<?php

declare(strict_types=1);

namespace ChatGPTContext\Ollama;

/**
 * Utility for parsing raw LLM response text.
 */
final class LlmResponse
{
    /**
     * Extract the first complete JSON object from a raw LLM response.
     *
     * Handles:
     * - Markdown code fences (```json ... ```)
     * - Stray preamble/postamble text emitted despite instructions
     *
     * @throws \RuntimeException if no complete JSON object can be found, or if
     *                           the response appears to have been truncated mid-object
     *                           (likely hit max_tokens limit — try --max-tokens).
     */
    public static function extractJson(string $raw): string
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

        throw new \RuntimeException(
            'Incomplete JSON object in model response (response may have been truncated — try --max-tokens)',
        );
    }
}
