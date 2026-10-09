<?php

namespace FriendsOfRedaxo\FilePondUploader\Api;

use FriendsOfRedaxo\FilePondUploader\Config;
use Throwable;
use rex;
use Exception;
use rex_addon;
use rex_api_function;
use rex_api_result;
use rex_backend_login;
use rex_clang;
use rex_config;
use rex_i18n;
use rex_logger;
use rex_media;
use rex_plugin;
use rex_response;
use rex_sql;
use rex_sql_exception;
use rex_ycom_auth;

use FriendsOfRedaxo\MetaInfoLangFields\MetainfoLangHelper;

/**
 * Automatische MetaInfo-Feld-Erkennung für FilePond
 * Pragmatischer Ansatz: Vollautomatische Erkennung aller relevanten Felder.
 */
class AutoMetainfo extends rex_api_function
{
    use AuthorizesRequests;

    protected $published = true;

    /**
     * Zentrale Methode für das Senden von JSON-Antworten.
     *
     * @param array<string, mixed> $data
     */
    protected function sendResponse(array $data, int $statusCode = 200): never
    {
        rex_response::cleanOutputBuffers();
        // rex_response::sendJson() setzt den Status selbst, http_response_code() wuerde ueberschrieben.
        $statusMap = [
            400 => rex_response::HTTP_BAD_REQUEST,
            401 => rex_response::HTTP_UNAUTHORIZED,
            403 => rex_response::HTTP_FORBIDDEN,
            404 => rex_response::HTTP_NOT_FOUND,
            500 => rex_response::HTTP_INTERNAL_ERROR,
        ];
        rex_response::setStatus($statusMap[$statusCode] ?? rex_response::HTTP_OK);
        rex_response::sendJson($data);
        exit;
    }

    public function execute(): rex_api_result
    {
        try {
            $this->authorize();
        } catch (Throwable) {
            $this->sendResponse(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        // Keine der Aktionen schreibt in die Session -- Sperre sofort freigeben,
        // sonst warten parallele Backend-Requests derselben Session auf diesen.
        session_write_close();

        $action = rex_request('action', 'string');

        switch ($action) {
            case 'get_fields':
                $this->getMetaInfoFields();
                break;

            default:
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Unbekannte Aktion',
                ], 400);
        }

        return new rex_api_result(true);
    }

    /**
     * Prueft, ob das MediaPlace-Addon (falls installiert) ein EIGENES,
     * JSON-basiertes Alt-Text-Feld aktiv hat (Einstellungen -> "Eigene
     * Metadaten" + ein Feld vom Typ "alt" existiert, siehe
     * MediaPlace\AltTextStatus::findOwnAltField() fuer dieselbe Pruefung
     * dort). Rein soft-optional: filepond_uploader listet mediaplace nicht
     * als requires-Paket, ohne installiertes/aktives MediaPlace bleibt
     * dieses Feld einfach inaktiv und die Klassik-med_alt-Logik greift wie
     * bisher unveraendert.
     *
     * public static (statt private), damit pages/settings.php denselben
     * Check fuer den Status-Hinweis im AI-Alt-Text-Fieldset nutzen kann,
     * ohne die Erkennungslogik zu duplizieren -- keine $this-Nutzung, war
     * also gefahrlos statisch machbar.
     *
     * @return array{active: bool, key: string}
     */
    public static function getMediaplaceOwnAltField(): array
    {
        if (!rex_addon::exists('mediaplace') || !rex_addon::get('mediaplace')->isAvailable()) {
            return ['active' => false, 'key' => 'alt'];
        }

        $ownMetaEnabledRaw = rex_config::get('mediaplace', 'enable_own_metadata', false);
        $ownMetaEnabled = in_array($ownMetaEnabledRaw, [1, '1', true, 'true', '|1|'], true);
        if (!$ownMetaEnabled || !class_exists('\FriendsOfRedaxo\Mediaplace\MetainfoFieldGroup')) {
            return ['active' => false, 'key' => 'alt'];
        }

        foreach (\FriendsOfRedaxo\Mediaplace\MetainfoFieldGroup::getFields() as $mpField) {
            if ('alt' === $mpField->getWidgetType()) {
                return ['active' => true, 'key' => $mpField->getKey()];
            }
        }

        return ['active' => false, 'key' => 'alt'];
    }

    /**
     * Konfiguration fuer die KI-Buttons im Medienpool (mediapool_ai.js). Wird
     * per rex_view::setJsProperty() direkt in die Backend-Seite geschrieben,
     * die API-Aktion get_ai_target_field bleibt fuer bestehende Aufrufer.
     *
     * @return array{enabled: bool, target_field: string, languages: array<string, string>, fallback_language: string, blocked_languages: list<string>, mediaplace_own_alt_active: bool, mediaplace_own_alt_key: string}
     */
    public static function getAiButtonConfig(): array
    {
        // KI aus: nichts berechnen (wird auf jeder Backend-Seite abgefragt)
        if (!Config::isEnabled('enable_ai_alt') || !Config::isEnabled('enable_ai_mediapool_detail', true)) {
            return ['enabled' => false, 'target_field' => 'med_alt', 'languages' => [], 'fallback_language' => 'en', 'blocked_languages' => [], 'mediaplace_own_alt_active' => false, 'mediaplace_own_alt_key' => 'alt'];
        }

        $enabled = true;
        $targetField = Config::string('ai_target_field', 'med_alt');
        // Nur sichere Feldnamen zulassen
        if (1 !== preg_match('/^[a-zA-Z0-9_]+$/', $targetField)) {
            $targetField = 'med_alt';
        }

        // Sprach-Mapping (clang_id => code) für mehrsprachige Felder
        $languages = [];
        foreach (rex_clang::getAll() as $clang) {
            $languages[(string) $clang->getId()] = $clang->getCode();
        }

        $fallbackLanguage = Config::aiFallbackLanguage();
        $blockedLanguages = array_values(array_diff(Config::aiBlockedLanguages(), [$fallbackLanguage]));

        $mediaplaceOwnAlt = self::getMediaplaceOwnAltField();

        return [
            'enabled' => $enabled,
            'target_field' => $targetField,
            'languages' => $languages,
            'fallback_language' => $fallbackLanguage,
            'blocked_languages' => $blockedLanguages,
            'i18n' => [
                'generate' => rex_i18n::rawMsg('filepond_ai_btn_generate'),
                'generateAll' => rex_i18n::rawMsg('filepond_ai_btn_generate_all'),
                'noFilename' => rex_i18n::rawMsg('filepond_ai_no_filename'),
                'noLanguageFields' => rex_i18n::rawMsg('filepond_ai_no_language_fields'),
                'error' => rex_i18n::rawMsg('filepond_error'),
                'unknown' => rex_i18n::rawMsg('filepond_error_unknown'),
                'skipped' => rex_i18n::rawMsg('filepond_ai_skipped'),
            ],
            // Eigenes MediaPlace-Alt-Feld hat Vorrang vor dem klassischen
            // med_alt/ai_target_field (siehe AltTextStatus::isMissing() dort:
            // dieselbe Prioritaet gilt fuer die Anzeige des Fehlt-Hinweises).
            // Ist es aktiv, haengt mediapool_ai.js den AI-Button NUR an das
            // eigene Feld, nicht zusaetzlich an med_alt.
            'mediaplace_own_alt_active' => $mediaplaceOwnAlt['active'],
            'mediaplace_own_alt_key' => $mediaplaceOwnAlt['key'],
        ];
    }

    /**
     * Automatische Erkennung aller relevanten MetaInfo-Felder
     * Lädt dynamisch alle Felder, die mit "med_" beginnen, filtert aber ausgeblendete Felder.
     */
    private function getMetaInfoFields(): void
    {
        try {
            $fields = [];

            // Konfigurierte Blacklist laden
            $excludedFields = rex_config::get('filepond_uploader', 'excluded_metadata_fields', []);
            if (!is_array($excludedFields)) {
                // rex_config_form speichert Arrays oft als pipe-separierten String (|value|value|)
                if (is_string($excludedFields) && str_contains($excludedFields, '|')) {
                    $excludedFields = array_filter(explode('|', $excludedFields), static fn (string $v): bool => '' !== $v);
                } elseif (is_string($excludedFields)) {
                    // Fallback, falls CSV
                    $excludedFields = explode(',', $excludedFields);
                } else {
                    $excludedFields = [];
                }
            }

            // 1. Titel ist immer dabei (REDAXO Standard)
            // Nur hinzufügen wenn nicht ausgeschlossen
            if (!in_array('title', $excludedFields, true)) {
                $fields[] = $this->analyzeField('title');
            }

            // 2. Prüfen ob MetaInfo Addon verfügbar ist
            $hasMetaInfo = rex_addon::exists('metainfo') && rex_addon::get('metainfo')->isAvailable();

            if (!$hasMetaInfo) {
                // Fallback ohne MetaInfo: Nur die absoluten Standardfelder annehmen
                $defaults = ['med_alt', 'med_copyright', 'med_description'];
                foreach ($defaults as $f) {
                    if (!in_array($f, $excludedFields, true)) {
                        $fields[] = $this->analyzeField($f);
                    }
                }
            } else {
                // 3. Dynamisch ALLE med_ Felder aus MetaInfo laden – mit Title und Type in einer JOIN-Query
                $sql = rex_sql::factory();
                $sql->setQuery('
                    SELECT mf.name, mf.title, mt.label AS type_label
                    FROM ' . rex::getTable('metainfo_field') . ' mf
                    LEFT JOIN ' . rex::getTable('metainfo_type') . ' mt ON mf.type_id = mt.id
                    WHERE mf.name LIKE "med_%"
                    ORDER BY mf.priority
                ');

                foreach ($sql as $row) {
                    $name = (string) ($row->getValue('name') ?? '');
                    $title = (string) ($row->getValue('title') ?? '');
                    $typeLabel = (string) ($row->getValue('type_label') ?? '');
                    if (!in_array($name, $excludedFields, true)) {
                        $fields[] = $this->analyzeField($name, $title, $typeLabel);
                    }
                }
            }

            // Duplikate entfernen
            $uniqueFields = [];
            $seenNames = [];
            foreach ($fields as $field) {
                if (!in_array($field['name'], $seenNames, true)) {
                    $seenNames[] = $field['name'];
                    $uniqueFields[] = $field;
                }
            }

            $this->sendResponse([
                'success' => true,
                'fields' => $uniqueFields,
            ]);
        } catch (Exception $e) {
            rex_logger::logException($e);

            $this->sendResponse([
                'success' => false,
                'error' => rex_i18n::rawMsg('filepond_err_load_fields'),
            ], 500);
        }
    }

    /**
     * Prüft ob ein Feld existiert (in Standard-Tabelle oder MetaInfo).
     */
    private function fieldExists(string $fieldName): bool
    {
        // Standard-Felder existieren immer
        if (in_array($fieldName, ['title', 'med_alt', 'med_copyright'], true)) {
            return true;
        }

        // Prüfe in MetaInfo
        if (rex_addon::exists('metainfo') && rex_addon::get('metainfo')->isAvailable()) {
            try {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT id FROM rex_metainfo_field WHERE name = ?', [$fieldName]);
                return $sql->getRows() > 0;
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Prüft ob ein Feld ein Pflichtfeld ist.
     */
    private function isFieldRequired(string $fieldName): bool
    {
        // 1. Prüfe alten Schalter für Titel
        if ('title' === $fieldName && (bool) rex_config::get('filepond_uploader', 'title_required_default', 0)) {
            return true;
        }

        // 2. Prüfe neue kommaseparierte Liste
        $requiredFields = (string) rex_config::get('filepond_uploader', 'required_metadata_fields', '');
        if ('' === $requiredFields) {
            return false;
        }

        $fields = array_map('trim', explode(',', $requiredFields));
        return in_array($fieldName, $fields, true);
    }

    /**
     * Analysiert ein Feld auf Typ und Mehrsprachigkeit.
     *
     * @param string $metainfoTitle   Vorgeladener MetaInfo-Titel (aus JOIN-Query)
     * @param string $metainfoTypeLabel Vorgeladener MetaInfo-Typ-Label (aus JOIN-Query)
     * @return array{name: string, label: string, type: string, multilingual: bool, required: bool, languages: list<array{code: string, name: string, id: int}>}
     */
    private function analyzeField(string $fieldName, string $metainfoTitle = '', string $metainfoTypeLabel = ''): array
    {
        // Standard-Feld-Informationen
        $fieldInfo = [
            'name' => $fieldName,
            'label' => $this->getFieldLabel($fieldName, $metainfoTitle),
            'type' => $this->getFieldType($fieldName, $metainfoTypeLabel),
            'multilingual' => $this->isMultilingual($fieldName, $metainfoTypeLabel),
            'required' => $this->isFieldRequired($fieldName),
            'languages' => [],
        ];

        // Wenn mehrsprachig, alle verfügbaren Sprachen laden
        if ($fieldInfo['multilingual']) {
            $fieldInfo['languages'] = $this->getAvailableLanguages();
        }

        return $fieldInfo;
    }

    /**
     * Ermittelt das Label für ein Feld.
     *
     * @param string $metainfoTitle Vorgeladener MetaInfo-Titel (aus JOIN-Query, leer = aus DB laden)
     */
    private function getFieldLabel(string $fieldName, string $metainfoTitle = ''): string
    {
        // 1. Humanisierter Feldname als Basis-Fallback (besser als roher Spaltenname)
        $label = $this->humanizeFieldName($fieldName);

        // 2. Standard Labels für bekannte Felder
        $standardLabels = [
            'title' => 'Titel',
            'med_alt' => 'Alt-Text',
            'med_copyright' => 'Copyright',
            'med_description' => 'Beschreibung',
        ];

        if (isset($standardLabels[$fieldName])) {
            $label = $standardLabels[$fieldName];
        }

        // 3. MetaInfo-Titel aus DB laden, falls nicht vorgeladen
        if ('' === $metainfoTitle && rex_addon::exists('metainfo') && rex_addon::get('metainfo')->isAvailable()) {
            try {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT title FROM ' . rex::getTable('metainfo_field') . ' WHERE name = ?', [$fieldName]);
                if ($sql->getRows() > 0) {
                    $metainfoTitle = (string) ($sql->getValue('title') ?? '');
                }
            } catch (Exception $e) {
                // Ignore
            }
        }

        // 4. MetaInfo-Titel anwenden (hat höchste Priorität)
        if ('' !== $metainfoTitle) {
            if (str_starts_with($metainfoTitle, 'translate:')) {
                // Übersetzungs-Key: nur verwenden wenn die Übersetzung tatsächlich gefunden wurde
                $key = substr($metainfoTitle, 10);
                $translated = rex_i18n::msg($key);
                // In Produktion gibt msg() den Key selbst zurück wenn nicht gefunden;
                // im Debug-Modus '**key**'. Nur verwenden wenn die Übersetzung abweicht.
                if ($translated !== $key && $translated !== '**' . $key . '**') {
                    $label = $translated;
                }
                // Sonst: Standard- oder humanisierter Fallback bleibt erhalten
            } elseif ($metainfoTitle !== $fieldName) {
                // Direkter Label-Wert (nicht verwenden wenn er identisch mit dem Spaltennamen ist)
                $label = $metainfoTitle;
            }
        }

        return $label;
    }

    /**
     * Erzeugt einen lesbaren Namen aus einem Spaltennamen.
     * Entfernt `med_`-Prefix, ersetzt Unterstriche durch Leerzeichen.
     */
    private function humanizeFieldName(string $fieldName): string
    {
        $name = preg_replace('/^med_/', '', $fieldName) ?? $fieldName;
        $name = str_replace('_', ' ', $name);
        return ucwords($name);
    }

    /**
     * Ermittelt den Feldtyp.
     *
     * @param string $metainfoTypeLabel Vorgeladener MetaInfo-Typ-Label (aus JOIN-Query, leer = aus DB laden)
     */
    private function getFieldType(string $fieldName, string $metainfoTypeLabel = ''): string
    {
        // Title ist speziell und fix
        if ('title' === $fieldName) {
            return 'text';
        }

        // Typ-Label aus DB laden, falls nicht vorgeladen
        if ('' === $metainfoTypeLabel && rex_addon::exists('metainfo') && rex_addon::get('metainfo')->isAvailable()) {
            try {
                $sql = rex_sql::factory();
                $sql->setQuery('
                    SELECT mt.label AS type_label
                    FROM ' . rex::getTable('metainfo_field') . ' mf
                    LEFT JOIN ' . rex::getTable('metainfo_type') . ' mt ON mf.type_id = mt.id
                    WHERE mf.name = ?
                ', [$fieldName]);

                if ($sql->getRows() > 0) {
                    $metainfoTypeLabel = (string) ($sql->getValue('type_label') ?? '');
                }
            } catch (Exception $e) {
                // Ignore errors
            }
        }

        if ('' !== $metainfoTypeLabel && str_contains($metainfoTypeLabel, 'textarea')) {
            return 'textarea';
        }

        if ('' !== $metainfoTypeLabel) {
            return 'text';
        }

        // Fallbacks für Standard-Felder wenn MetaInfo-Lookup fehlschlägt/nicht existiert
        if ('med_description' === $fieldName) {
            return 'textarea';
        }

        return 'text';
    }

    /**
     * Prüft ob ein Feld mehrsprachig konfiguriert ist.
     *
     * @param string $metainfoTypeLabel Vorgeladener MetaInfo-Typ-Label (aus JOIN-Query, leer = aus DB laden)
     */
    private function isMultilingual(string $fieldName, string $metainfoTypeLabel = ''): bool
    {
        // Prüfe MetaInfo Lang Fields AddOn
        if (!rex_addon::exists('metainfo_lang_fields') || !rex_addon::get('metainfo_lang_fields')->isAvailable()) {
            return false;
        }

        // Prüfe ob MetaInfo AddOn verfügbar ist
        if (!rex_addon::exists('metainfo') || !rex_addon::get('metainfo')->isAvailable()) {
            return false;
        }

        // Typ-Label aus DB laden, falls nicht vorgeladen
        if ('' === $metainfoTypeLabel) {
            try {
                $sql = rex_sql::factory();
                $sql->setQuery('
                    SELECT mt.label AS type_label
                    FROM ' . rex::getTable('metainfo_field') . ' mf
                    LEFT JOIN ' . rex::getTable('metainfo_type') . ' mt ON mf.type_id = mt.id
                    WHERE mf.name = ?
                ', [$fieldName]);

                if ($sql->getRows() > 0) {
                    $metainfoTypeLabel = (string) ($sql->getValue('type_label') ?? '');
                }
            } catch (Exception $e) {
                return false;
            }
        }

        $multilingualTypes = ['lang_text', 'lang_textarea', 'lang_text_all', 'lang_textarea_all'];
        return in_array($metainfoTypeLabel, $multilingualTypes, true);
    }

    /**
     * Lädt alle verfügbaren Sprachen.
     *
     * @return list<array{code: string, name: string, id: int}>
     */
    private function getAvailableLanguages(): array
    {
        $languages = [];
        foreach (rex_clang::getAll() as $clang) {
            $languages[] = [
                'code' => $clang->getCode(),
                'name' => $clang->getName(),
                'id' => $clang->getId(),
            ];
        }
        return $languages;
    }

}
