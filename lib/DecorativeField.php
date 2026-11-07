<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex;
use rex_media;
use rex_media_cache;
use rex_sql;


/**
 * Metainfo-Checkbox „Dekoratives Bild“ (med_alt_decorative), wie sie z. B. MediaPlace anlegt.
 *
 * Gibt es das Feld, ist es die gemeinsame Quelle für „dekorativ“: Upload-Dialog, Alt-Text-Checker
 * und Frontend-Ausgaben (z. B. media_srcset, eigene Projekt-Klassen) lesen denselben Wert.
 * Gespeichert wird wie bei Metainfo-Checkboxen: Schlüssel der Option zwischen Pipes (ohne Optionen "|true|"), leer = aus.
 */
final class DecorativeField
{
    public const NAME = 'med_alt_decorative';

    /** Spalte vorhanden? */
    public static function exists(): bool
    {
        static $exists = null;
        if (null === $exists) {
            $exists = false;
            foreach (rex_sql::showColumns(rex::getTable('media')) as $column) {
                if (self::NAME === $column['name']) {
                    $exists = true;
                    break;
                }
            }
        }

        return $exists;
    }

    /** Gespeicherter Wert bedeutet „dekorativ“ ("|true|", "|1|" …). */
    public static function isOn(mixed $value): bool
    {
        return MetainfoCheckbox::isOn($value);
    }

    /** Wert zum Speichern, passend zur Metainfo-Checkbox (ohne Optionen "|true|"). */
    public static function value(bool $on): string
    {
        return $on ? (MetainfoCheckbox::onValue(self::NAME) ?? '|true|') : '';
    }

    /**
     * Dateinamen aller Medien mit gesetztem Feld.
     *
     * @return list<string>
     */
    public static function filenames(): array
    {
        if (!self::exists()) {
            return [];
        }
        $files = [];
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT filename, ' . self::NAME . ' FROM ' . rex::getTable('media') . ' WHERE ' . self::NAME . ' IS NOT NULL AND ' . self::NAME . ' <> ""');
        foreach ($sql as $row) {
            if (self::isOn($row->getValue(self::NAME))) {
                $files[] = (string) $row->getValue('filename');
            }
        }

        return $files;
    }

    /** Feld für eine Datei setzen oder löschen. */
    public static function set(string $filename, bool $on): void
    {
        if (!self::exists()) {
            return;
        }
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('media'));
        $sql->setWhere(['filename' => $filename]);
        $sql->setValue(self::NAME, self::value($on));
        $sql->update();
        rex_media_cache::delete($filename);
        rex_media::clearInstance($filename);
    }
}
