<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex;
use rex_addon;
use rex_clang;
use rex_extension;
use rex_extension_point;
use rex_media;
use rex_media_cache;
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

    /** Felder, die Frontend-Uploads immer setzen dürfen (sofern vorhanden und nicht ausgeblendet). */
    private const FRONTEND_FIELDS = ['title', 'med_alt', 'med_copyright', 'med_description'];

    /** Größte Länge eines Werts aus dem Upload-Dialog. */
    private const MAX_VALUE_LENGTH = 5000;

    /**
     * Schreibt nur Felder, die der Metadaten-Dialog anbietet (title und Metainfo-Felder med_*,
     * ohne ausgeblendete). Frontend-Uploads schreiben zusätzlich nur freigegebene Felder:
     * FRONTEND_FIELDS, die Pflichtfelder aus den Einstellungen und was der Extension Point
     * FILEPOND_FRONTEND_METADATA_FIELDS ergänzt.
     *
     * @param array<string, mixed> $metadata
     */
    public static function apply(string $filename, array $metadata, bool $frontend = false): void
    {
        foreach (self::ALIASES as $alias => $column) {
            if (array_key_exists($alias, $metadata) && !array_key_exists($column, $metadata)) {
                $metadata[$column] = $metadata[$alias];
            }
        }

        $decorative = true === ($metadata['decorative'] ?? false);
        $columns = $frontend ? self::frontendColumns() : self::writableColumns();

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
            // Cache und gemerkte Instanz leeren: seit REDAXO 5.21.5 merkt sich der Instance-Pool auch „nicht gefunden“
            // (rex_media_service::addMedia() fragt beim Suchen eines freien Namens rex_media::get() ab), vgl. uppy #51
            rex_media_cache::delete($filename);
            rex_media::clearInstance($filename);
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
        if (!is_scalar($value)) {
            return '';
        }
        // Tags und Steuerzeichen entfernen (Zeilenumbrüche bleiben), Länge begrenzen
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags((string) $value));

        return trim(mb_substr($value, 0, self::MAX_VALUE_LENGTH));
    }

    /**
     * Felder des Metadaten-Dialogs: title plus Metainfo-Felder med_* (ohne Metainfo die
     * Standardfelder), ohne ausgeblendete Felder, nur vorhandene Spalten.
     *
     * @return list<string>
     */
    public static function writableColumns(): array
    {
        static $columns = null;
        if (null !== $columns) {
            return $columns;
        }

        $existing = [];
        foreach (rex_sql::showColumns(rex::getTable('media')) as $column) {
            $existing[] = $column['name'];
        }

        $candidates = ['title'];
        if (rex_addon::get('metainfo')->isAvailable()) {
            $sql = rex_sql::factory();
            $sql->setQuery('SELECT name FROM ' . rex::getTable('metainfo_field') . ' WHERE name LIKE "med\\_%"');
            foreach ($sql as $row) {
                $candidates[] = (string) $row->getValue('name');
            }
        } else {
            $candidates = array_merge($candidates, ['med_alt', 'med_copyright', 'med_description']);
        }

        $excluded = Config::excludedMetadataFields();
        $columns = array_values(array_unique(array_filter(
            $candidates,
            static fn (string $name): bool => in_array($name, $existing, true) && !in_array($name, $excluded, true),
        )));

        return $columns;
    }

    /**
     * Felder, die Frontend-Uploads setzen dürfen.
     *
     * @return list<string>
     */
    public static function frontendColumns(): array
    {
        $allowed = self::FRONTEND_FIELDS;
        foreach (explode(',', Config::string('required_metadata_fields')) as $field) {
            $allowed[] = trim($field);
        }
        $allowed = rex_extension::registerPoint(new rex_extension_point('FILEPOND_FRONTEND_METADATA_FIELDS', $allowed));
        $allowed = is_array($allowed) ? $allowed : self::FRONTEND_FIELDS;

        return array_values(array_intersect(self::writableColumns(), $allowed));
    }
}
