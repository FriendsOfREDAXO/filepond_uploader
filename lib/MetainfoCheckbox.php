<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex;
use rex_addon;
use rex_sql;

use function array_key_exists;
use function count;
use function in_array;
use function is_scalar;

/**
 * Einfache Metainfo-Checkboxen (eine Option) im Upload-Dialog.
 *
 * Der Medienpool speichert den Schlüssel der Option zwischen Pipes: ohne Optionen "|true|"
 * (Metainfo-Boolean), bei genau einer Option deren Schlüssel, z. B. "|1|". Nur mit genau diesem
 * Wert zeigt der Medienpool den Haken wieder an. Checkbox-Gruppen mit mehreren Optionen oder
 * SQL-Optionen bietet der Dialog nicht an.
 */
final class MetainfoCheckbox
{
    /** @var array<string, string|null> */
    private static array $onValues = [];

    /**
     * Gespeicherter Wert für „an“ (z. B. "|true|"), null wenn das Feld keine einfache Checkbox ist.
     */
    public static function onValue(string $field): ?string
    {
        if (array_key_exists($field, self::$onValues)) {
            return self::$onValues[$field];
        }

        $value = null;
        if (rex_addon::get('metainfo')->isAvailable()) {
            $sql = rex_sql::factory();
            $sql->setQuery('SELECT params FROM ' . rex::getTable('metainfo_field') . ' WHERE name = ?', [$field]);
            if (1 === $sql->getRows()) {
                $value = self::keyFromParams((string) ($sql->getValue('params') ?? ''));
            }
        } elseif (DecorativeField::NAME === $field) {
            $value = '|true|';
        }

        return self::$onValues[$field] = $value;
    }

    /** Dialog-Wert ("|1|", "1", true …) in den gespeicherten Wert umsetzen. */
    public static function normalize(string $field, mixed $value): string
    {
        $on = self::onValue($field) ?? '|true|';

        return self::isOn($value) ? $on : '';
    }

    /** Gespeicherter oder übermittelter Wert bedeutet „an“. */
    public static function isOn(mixed $value): bool
    {
        if (true === $value) {
            return true;
        }
        if (!is_scalar($value)) {
            return false;
        }
        $value = trim((string) $value, "| \t\n\r");

        return '' !== $value && !in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
    }

    private static function keyFromParams(string $params): ?string
    {
        $params = trim($params);
        if ('' === $params) {
            return '|true|';
        }
        if ('SELECT' === rex_sql::getQueryType($params)) {
            return null;
        }
        $options = explode('|', $params);
        if (1 !== count($options)) {
            return null;
        }
        $option = $options[0];
        $key = str_contains($option, ':') && !str_starts_with($option, 'translate:') ? explode(':', $option, 2)[0] : $option;

        return '|' . $key . '|';
    }
}
