<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex;
use rex_config;
use rex_extension_point;
use rex_logger;
use rex_sql;
use rex_yform_manager_table;

use function count;
use function sprintf;

/**
 * Extension Point Handler für MEDIA_IS_IN_USE
 * Prüft ob Medien in YForm-Feldern vom Typ filepond verwendet werden.
 */
class MediaCleanup
{
    /**
     * Prüft ob ein Medium in YForm-Tabellen verwendet wird.
     *
     * @param rex_extension_point<list<string>> $ep
     * @return list<string> Liste von Warnungen
     */
    public static function isMediaInUse(rex_extension_point $ep): array
    {
        $warnings = $ep->getSubject();

        $filename = $ep->getParam('filename');
        if (null === $filename || '' === $filename) {
            return $warnings;
        }

        // Ignore-Parameter aus Extension Point oder $GLOBALS
        $ignoreTable = $ep->getParam('ignore_table');
        $ignoreId = $ep->getParam('ignore_id');
        $ignoreField = $ep->getParam('ignore_field');

        // Fallback auf $GLOBALS wenn EP-Parameter leer (für internen deleteMedia()-Aufruf)
        if (null === $ignoreTable && isset($GLOBALS['filepond_cleanup_ignore'])) {
            if (rex::isDebugMode() && (bool) rex_config::get('filepond_uploader', 'enable_debug_logging', false)) {
                rex_logger::factory()->debug('FilePondMediaCleanup: Verwende globale ignore-Parameter für {filename}', ['filename' => $filename]);
            }
            $ignoreTable = $GLOBALS['filepond_cleanup_ignore']['table'] ?? null;
            $ignoreId = $GLOBALS['filepond_cleanup_ignore']['id'] ?? null;
            $ignoreField = $GLOBALS['filepond_cleanup_ignore']['field'] ?? null;
        }

        foreach (self::findUsages((string) $filename, $ignoreTable, null === $ignoreId ? null : (int) $ignoreId, $ignoreField) as $usage) {
            $warnings[] = sprintf('FilePond Feld "%s" in Tabelle "%s" (ID: %s)', $usage['field'], $usage['label'], implode(', ', $usage['ids']));
        }

        return $warnings;
    }

    public static function isUsedInFilepondField(string $filename): bool
    {
        return [] !== self::findUsages($filename);
    }

    /**
     * Fundstellen einer Datei in YForm-Feldern vom Typ filepond (kommagetrennte Liste).
     *
     * @return list<array{table: string, label: string, field: string, ids: list<int>}>
     */
    public static function findUsages(string $filename, ?string $ignoreTable = null, ?int $ignoreId = null, ?string $ignoreField = null): array
    {
        if ('' === $filename || !class_exists(rex_yform_manager_table::class)) {
            return [];
        }

        $sql = rex_sql::factory();
        $usages = [];
        foreach (rex_yform_manager_table::getAll() as $table) {
            foreach ($table->getFields() as $field) {
                if ('value' !== $field->getType() || 'filepond' !== $field->getTypeName()) {
                    continue;
                }
                $tableName = $table->getTableName();
                $fieldName = $field->getName();
                if ($ignoreTable === $tableName && $ignoreField === $fieldName && null === $ignoreId) {
                    continue;
                }

                $query = 'SELECT id FROM ' . $sql->escapeIdentifier($tableName) . ' WHERE FIND_IN_SET(:filename, ' . $sql->escapeIdentifier($fieldName) . ')';
                $params = ['filename' => $filename];
                if ($ignoreTable === $tableName && null !== $ignoreId) {
                    $query .= ' AND id != :id';
                    $params['id'] = $ignoreId;
                }

                $ids = array_map('intval', array_column($sql->getArray($query, $params), 'id'));
                if ([] !== $ids) {
                    $usages[] = ['table' => $tableName, 'label' => '' !== $table->getName() ? $table->getName() : $tableName, 'field' => $fieldName, 'ids' => $ids];
                }
            }
        }

        return $usages;
    }
}
