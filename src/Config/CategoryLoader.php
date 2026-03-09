<?php

declare(strict_types=1);

namespace ChatGPTContext\Config;

/**
 * Loads and saves user-defined categories from/to categories.json.
 *
 * The categories.json file lives in the project root and is .gitignored —
 * each user maintains their own personal taxonomy there. The shipped
 * categories.json.example shows the expected schema.
 */
final class CategoryLoader
{
    /**
     * Load categories from a JSON file.
     *
     * Returns an empty array if the file does not exist (callers handle
     * the missing-file UX themselves, because the correct behaviour differs
     * per command: explore auto-switches to free mode, others print a hint).
     *
     * @return array<array{slug: string, name: string, description: string, keywords: array<string>}>
     *
     * @throws \RuntimeException if the file exists but is not valid JSON or has wrong structure.
     */
    public static function load(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read categories file: {$path}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $e) {
            throw new \RuntimeException("categories.json is not valid JSON: {$e->getMessage()}");
        }

        if (!is_array($data)) {
            throw new \RuntimeException("categories.json must contain a JSON array at the top level.");
        }

        foreach ($data as $i => $cat) {
            if (!isset($cat['slug'], $cat['name'])) {
                throw new \RuntimeException(
                    "categories.json entry #{$i} is missing required fields 'slug' and/or 'name'."
                    );
            }
            // Normalise optional fields so callers don't have to null-check
            $data[$i]['description'] = $cat['description'] ?? '';
            $data[$i]['keywords'] = $cat['keywords'] ?? [];
        }

        return $data;
    }

    /**
     * Save categories to a JSON file (used by explore --apply).
     *
     * If the file already exists, the new categories are merged in
     * (by slug, new entries win) so existing user categories are never lost.
     *
     * @param array<array{slug: string, name: string, description: string, keywords: array<string>}> $newCategories
     *
     * @throws \RuntimeException on write failure.
     */
    public static function save(string $path, array $newCategories): void
    {
        // Load existing so we can merge
        $existing = file_exists($path) ?self::load($path) : [];

        $bySlug = [];
        foreach ($existing as $cat) {
            $bySlug[$cat['slug']] = $cat;
        }
        foreach ($newCategories as $cat) {
            $bySlug[$cat['slug']] = $cat;
        }

        $merged = array_values($bySlug);

        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Failed to write categories file: {$path}");
        }
    }

    /**
     * Return true if the categories file exists at the given path.
     */
    public static function exists(string $path): bool
    {
        return file_exists($path);
    }
}