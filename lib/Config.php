<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex_config;

use function in_array;
use function is_array;
use function is_string;

/**
 * Lesezugriff auf die Einstellungen mit einheitlicher Auslegung.
 */
final class Config
{
    private const NS = 'filepond_uploader';

    /**
     * Schalter: package.yml liefert true/false, Checkboxen speichern "|1|", Selects "1"/"0".
     */
    public static function isEnabled(string $key, bool $default = false): bool
    {
        $raw = rex_config::get(self::NS, $key);
        if (null === $raw || '' === $raw) {
            return null === $raw ? $default : false;
        }

        return in_array($raw, [1, '1', true, 'true', '|1|'], true);
    }

    public static function int(string $key, int $default): int
    {
        $raw = rex_config::get(self::NS, $key);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $raw = rex_config::get(self::NS, $key);

        return is_string($raw) && '' !== trim($raw) ? trim($raw) : $default;
    }

    /** Fallback-Sprache für KI-Alt-Texte, zweistelliger Code. */
    public static function aiFallbackLanguage(): string
    {
        $code = strtolower(substr(self::string('ai_fallback_language', 'en'), 0, 2));

        return 1 === preg_match('/^[a-z]{2}$/', $code) ? $code : 'en';
    }

    /**
     * Sprachen, die das KI-Modell nicht direkt erzeugen soll (Multiselect "|de|en|" oder
     * ältere, frei eingegebene Listen "EN, de_DE").
     *
     * @return list<string>
     */
    public static function aiBlockedLanguages(): array
    {
        $raw = rex_config::get(self::NS, 'ai_blocked_languages', '');
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
        } else {
            return [];
        }

        $codes = [];
        foreach ($parts as $part) {
            $code = is_string($part) ? strtolower(substr(trim($part), 0, 2)) : '';
            if (1 === preg_match('/^[a-z]{2}$/', $code) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
