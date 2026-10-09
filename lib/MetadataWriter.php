<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex;
use rex_clang;
use rex_sql;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_scalar;

/**
 * Schreibt die Metadaten aus dem Upload-Dialog in rex_media.
 */
final class MetadataWriter
{
    /** Ältere Dialog-Schlüssel => Spalte */
    private const ALIASES = ['alt' => 'med_alt', 'copyright' => 'med_copyright'];

    /**
     * @param array<string, mixed> $metadata
     */
    public static function apply(string $filename, array $metadata): void
    {
        foreach (self::ALIASES as $alias => $column) {
            if (array_key_exists($alias, $metadata) && !array_key_exists($column, $metadata)) {
                $metadata[$column] = $metadata[$alias];
            }
        }

        $decorative = true === ($metadata['decorative'] ?? false);
        $columns = self::writableColumns();

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('media'));
        $sql->setWhere(['filename' => $filename]);

        $hasValues = false;
        foreach ($metadata as $field => $value) {
            if (!in_array($field, $columns, true)) {
                continue;
            }
            if ($decorative && 'med_alt' === $field) {
                continue;
            }
            $sql->setValue($field, is_array($value) ? self::toLangJson($value) : self::clean($value));
            $hasValues = true;
        }

        if ($decorative && in_array('med_alt', $columns, true)) {
            $sql->setValue('med_alt', '');
            $hasValues = true;
        }

        if ($hasValues) {
            $sql->update();
        }
    }

    /**
     * Frontend {"de": "Text"} => metainfo_lang_fields [{"clang_id": 1, "value": "Text"}].
     *
     * @param array<int|string, mixed> $values
     */
    private static function toLangJson(array $values): string
    {
        $result = [];
        foreach (rex_clang::getAll() as $clang) {
            if (array_key_exists($clang->getCode(), $values)) {
                $result[] = ['clang_id' => $clang->getId(), 'value' => self::clean($values[$clang->getCode()])];
            }
        }

        return (string) json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private static function clean(mixed $value): string
    {
        return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
    }

    /**
     * title plus alle vorhandenen Metainfo-Spalten med_* der Medientabelle.
     *
     * @return list<string>
     */
    private static function writableColumns(): array
    {
        static $columns = null;
        if (null === $columns) {
            $columns = ['title'];
            foreach (rex_sql::showColumns(rex::getTable('media')) as $column) {
                if (str_starts_with($column['name'], 'med_')) {
                    $columns[] = $column['name'];
                }
            }
        }

        return $columns;
    }
}
