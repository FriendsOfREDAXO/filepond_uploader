<?php

namespace FriendsOfRedaxo\FilePondUploader;

use Exception;
use rex_clang;
use rex_i18n;

/**
 * Helper class for formatting multilingual MetaInfo fields.
 */
class LangFormatter
{
    /**
     * Format multilingual field data for display.
     *
     * @param array<int, array{clang_id: int, value: string}> $langData
     */
    public static function formatMultilingualField(array $langData): string
    {
        $currentLang = rex_clang::getCurrentId();
        $descriptions = [];

        // First try to get current language
        foreach ($langData as $entry) {
            if ($entry['clang_id'] === $currentLang
                && '' !== trim($entry['value'])) {
                return strip_tags(trim($entry['value']));
            }
        }

        // Fallback: collect all non-empty descriptions
        foreach ($langData as $entry) {
            if ('' !== trim($entry['value'])) {
                $clang = rex_clang::get($entry['clang_id']);
                $langName = null !== $clang ? $clang->getName() : 'ID' . $entry['clang_id'];
                $descriptions[] = $langName . ': ' . strip_tags(trim($entry['value']));
            }
        }

        if (0 === count($descriptions)) {
            return rex_i18n::msg('filepond_no_description');
        }

        return implode(' | ', $descriptions);
    }

    /**
     * Check if a string looks like MetaInfo Lang Fields JSON.
     */
    public static function isMultilingualJson(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        // Quick check for JSON structure
        if (!str_starts_with($value, '[{') && !str_starts_with($value, '{')) {
            return false;
        }

        try {
            $data = json_decode($value, true);
            if (JSON_ERROR_NONE !== json_last_error() || !is_array($data)) {
                return false;
            }

            // Check if it has the MetaInfo Lang Fields structure
            foreach ($data as $entry) {
                if (is_array($entry) && isset($entry['clang_id'], $entry['value'])) {
                    return true;
                }
            }
        } catch (Exception $e) {
            return false;
        }

        return false;
    }
}
