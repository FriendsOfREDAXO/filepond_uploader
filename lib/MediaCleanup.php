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

        $sql = rex_sql::factory();
        $yformTables = rex_yform_manager_table::getAll();

        foreach ($yformTables as $table) {
            foreach ($table->getFields() as $field) {
                if ('value' === $field->getType() && 'filepond' === $field->getTypeName()) {
                    $tableName = $table->getTableName();
                    $fieldName = $field->getName();

                    // Überspringe das Feld das gerade bearbeitet wird
                    if ($ignoreTable === $tableName && $ignoreField === $fieldName) {
                        if (rex::isDebugMode() && (bool) rex_config::get('filepond_uploader', 'enable_debug_logging', false)) {
                            rex_logger::factory()->debug('FilePondMediaCleanup: Überspringe Feld {field} in {table}', ['field' => $fieldName, 'table' => $tableName]);
                        }
                        continue;
                    }

                    // Prüfe ob Datei in diesem Feld verwendet wird
                    $query = "SELECT id, $fieldName FROM $tableName WHERE FIND_IN_SET(:filename, $fieldName)";

                    // Wenn wir eine ID ignorieren sollen, schließe diese aus
                    if ($ignoreTable === $tableName && null !== $ignoreId) {
                        $query .= ' AND id != :id';
                        $result = $sql->getArray($query, [
                            ':filename' => $filename,
                            ':id' => $ignoreId,
                        ]);
                    } else {
                        $result = $sql->getArray($query, [':filename' => $filename]);
                    }

                    if (count($result) > 0) {
                        $tableLabelValue = $table->getName();
                        $tableLabel = ('' !== $tableLabelValue) ? $tableLabelValue : $tableName;
                        $warnings[] = sprintf(
                            'FilePond Feld "%s" in Tabelle "%s" (ID: %s)',
                            $fieldName,
                            $tableLabel,
                            implode(', ', array_column($result, 'id')),
                        );
                    }
                }
            }
        }

        return $warnings;
    }
}
