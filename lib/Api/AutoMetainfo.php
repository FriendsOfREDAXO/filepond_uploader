<?php

namespace FriendsOfRedaxo\FilePondUploader\Api;

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

    /**
     * Gleiche Pruefung wie Upload::isAuthorized():
     * Backend-User, API-Token oder YCom-User.
     */
    private function isAuthorized(): bool
    {
        if (null !== rex_backend_login::createUser()) {
            return true;
        }

        $apiToken = rex_config::get('filepond_uploader', 'api_token');
        $apiTokenStr = is_string($apiToken) ? $apiToken : '';
        $requestToken = rex_request('api_token', 'string', '');
        $sessionToken = rex_session('filepond_token', 'string', '');
        if ('' !== $apiTokenStr
            && (('' !== $requestToken && hash_equals($apiTokenStr, $requestToken))
                || ('' !== $sessionToken && hash_equals($apiTokenStr, $sessionToken)))
        ) {
            return true;
        }

        /** @phpstan-ignore class.notFound */
        return rex_plugin::get('ycom', 'auth')->isAvailable() && null !== rex_ycom_auth::getUser();
    }

    public function execute(): rex_api_result
    {
        if (!$this->isAuthorized()) {
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

            case 'get_ai_target_field':
                $this->getAiTargetField();
                break;

            case 'save_metadata':
                $this->saveMetadata();
                break;

            case 'load_metadata':
                $this->loadMetadata();
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

    private function getAiTargetField(): void
    {
        $this->sendResponse(['success' => true] + self::getAiButtonConfig());
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
        $isEnabled = static function (string $key, bool $default): bool {
            $raw = rex_config::get('filepond_uploader', $key, $default ? '1' : '0');

            return in_array($raw, [1, '1', true, 'true', '|1|'], true);
        };
        $globalEnabled = $isEnabled('enable_ai_alt', false);
        $mediapoolEnabled = $isEnabled('enable_ai_mediapool_detail', true);
        $enabled = $globalEnabled && $mediapoolEnabled;
        $targetFieldRaw = rex_config::get('filepond_uploader', 'ai_target_field', 'med_alt');
        $targetField = is_string($targetFieldRaw) ? trim($targetFieldRaw) : 'med_alt';
        if ('' === $targetField) {
            $targetField = 'med_alt';
        }

        // Nur sichere Feldnamen zulassen
        if (1 !== preg_match('/^[a-zA-Z0-9_]+$/', $targetField)) {
            $targetField = 'med_alt';
        }

        // Sprach-Mapping (clang_id => code) für mehrsprachige Felder
        $languages = [];
        foreach (rex_clang::getAll() as $clang) {
            $languages[(string) $clang->getId()] = $clang->getCode();
        }

        $fallbackLanguageRaw = rex_config::get('filepond_uploader', 'ai_fallback_language', 'en');
        $fallbackLanguage = is_string($fallbackLanguageRaw) ? strtolower(substr(trim($fallbackLanguageRaw), 0, 2)) : 'en';
        if (1 !== preg_match('/^[a-z]{2}$/', $fallbackLanguage)) {
            $fallbackLanguage = 'en';
        }

        $blockedRaw = rex_config::get('filepond_uploader', 'ai_blocked_languages', '');
        $blockedLanguages = [];
        if (is_array($blockedRaw)) {
            $parts = $blockedRaw;
        } elseif (is_string($blockedRaw)) {
            if (str_contains($blockedRaw, '|')) {
                $parts = array_values(array_filter(explode('|', $blockedRaw), static fn (string $v): bool => '' !== $v));
            } else {
                $parts = preg_split('/[\s,;]+/', strtolower($blockedRaw));
            }
        } else {
            $parts = [];
        }

        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (!is_string($part)) {
                    continue;
                }

                $short = strtolower(substr(trim($part), 0, 2));
                if (1 !== preg_match('/^[a-z]{2}$/', $short)) {
                    continue;
                }

                if (!in_array($short, $blockedLanguages, true) && $short !== $fallbackLanguage) {
                    $blockedLanguages[] = $short;
                }
            }
        }

        $mediaplaceOwnAlt = self::getMediaplaceOwnAltField();

        return [
            'enabled' => $enabled,
            'target_field' => $targetField,
            'languages' => $languages,
            'fallback_language' => $fallbackLanguage,
            'blocked_languages' => $blockedLanguages,
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
                'error' => 'Ein Fehler ist beim Laden der Metafelder aufgetreten',
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

    /**
     * Speichert Metadaten für eine Datei.
     */
    private function saveMetadata(): void
    {
        try {
            $fileId = rex_request('file_id', 'string');
            $metadata = rex_request('metadata', 'array');

            // Input validation
            if ('' === $fileId) {
                throw new Exception('Keine Datei-ID angegeben');
            }

            // Validate file_id format (filename pattern)
            if (1 !== preg_match('/^[a-zA-Z0-9._-]+$/', $fileId)) {
                throw new Exception('Ungültige Datei-ID');
            }

            if ([] === $metadata) {
                throw new Exception('Ungültige Metadaten');
            }

            // Prüfe ob Datei existiert
            $media = rex_media::get($fileId);
            if (null === $media) {
                throw new Exception('Mediendatei nicht gefunden');
            }

            // SQL für Update vorbereiten
            $sql = rex_sql::factory();
            $sql->setTable('rex_media');
            $sql->setWhere(['filename' => $fileId]);

            // Verarbeite jedes Feld mit Validierung
            foreach ($metadata as $fieldName => $fieldValue) {
                // Validiere Feldname gegen Whitelist
                if (!$this->isValidMetaInfoField($fieldName)) {
                    continue; // Skip invalid field names
                }

                if ($this->isMultilingual($fieldName)) {
                    // Mehrsprachiges Feld - konvertiere zu MetaInfo Lang Fields Format
                    $sanitizedValue = $this->sanitizeMetaInfoValue($fieldValue);
                    $langData = $this->convertToMetaInfoLangFormat($sanitizedValue);
                    $sql->setValue($fieldName, json_encode($langData));
                } else {
                    // Standard-Feld
                    $sanitizedValue = $this->sanitizeMetaInfoValue($fieldValue);
                    $sql->setValue($fieldName, $sanitizedValue);
                }
            }

            // SQL error handling
            try {
                $sql->update();
            } catch (rex_sql_exception $e) {
                throw new Exception('Fehler beim Speichern der Metadaten: ' . $e->getMessage());
            }

            $this->sendResponse([
                'success' => true,
                'message' => 'Metadaten erfolgreich gespeichert',
            ]);
        } catch (Exception $e) {
            // Log the exception internally for debugging
            rex_logger::logException($e);

            $this->sendResponse([
                'success' => false,
                'error' => 'Ein Fehler ist beim Speichern der Metadaten aufgetreten',
            ], 500);
        }
    }

    /**
     * Konvertiert Frontend-Daten ins MetaInfo Lang Fields Format
     * Frontend: {"de": "Text", "en": "Text"}
     * MetaInfo: [{"clang_id": 1, "value": "Text"}, {"clang_id": 2, "value": "Text"}].
     *
     * @return list<array{clang_id: int, value: string}>
     */
    private function convertToMetaInfoLangFormat(mixed $fieldValue): array
    {
        if (!is_array($fieldValue)) {
            return [];
        }

        $result = [];
        $languages = rex_clang::getAll();

        foreach ($fieldValue as $langCode => $value) {
            // Finde Sprach-ID anhand des Codes
            foreach ($languages as $clang) {
                if ($clang->getCode() === $langCode) {
                    $result[] = [
                        'clang_id' => $clang->getId(),
                        'value' => (string) $value,
                    ];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Lädt bestehende Metadaten für eine Datei.
     */
    private function loadMetadata(): void
    {
        try {
            $fileId = rex_request('file_id', 'string');

            // Input validation
            if ('' === $fileId) {
                throw new Exception('Keine Datei-ID angegeben');
            }

            // Validate file_id format (filename pattern)
            if (1 !== preg_match('/^[a-zA-Z0-9._-]+$/', $fileId)) {
                throw new Exception('Ungültige Datei-ID');
            }

            $media = rex_media::get($fileId);
            if (null === $media) {
                throw new Exception('Mediendatei nicht gefunden');
            }

            $metadata = [];

            // Lade alle verfügbaren Felder wie in getMetaInfoFields
            $standardFields = ['title', 'med_alt', 'med_copyright'];

            if ($this->fieldExists('med_title_lang')) {
                $standardFields[] = 'med_title_lang';
            }

            $optionalFields = ['med_description', 'med_keywords', 'med_source'];
            foreach ($optionalFields as $field) {
                if ($this->fieldExists($field)) {
                    $standardFields[] = $field;
                }
            }

            foreach ($standardFields as $fieldName) {
                $fieldInfo = $this->analyzeField($fieldName);
                $fieldValue = $media->getValue($fieldName);

                if ($fieldInfo['multilingual']) {
                    // Mehrsprachiges Feld - konvertiere von MetaInfo Lang Fields Format
                    $metadata[$fieldName] = $this->convertFromMetaInfoLangFormat($fieldValue);
                } else {
                    // Standard-Feld
                    $metadata[$fieldName] = $fieldValue;
                }
            }

            $this->sendResponse([
                'success' => true,
                'metadata' => $metadata,
            ]);
        } catch (Exception $e) {
            // Log the exception internally for debugging
            rex_logger::logException($e);

            $this->sendResponse([
                'success' => false,
                'error' => 'Ein Fehler ist beim Laden der Metadaten aufgetreten',
            ], 500);
        }
    }

    /**
     * Konvertiert MetaInfo Lang Fields Format ins Frontend-Format
     * MetaInfo: [{"clang_id": 1, "value": "Text"}, {"clang_id": 2, "value": "Text"}]
     * Frontend: {"de": "Text", "en": "Text"}.
     *
     * @return array<string, string>
     */
    private function convertFromMetaInfoLangFormat(mixed $jsonData): array
    {
        if (null === $jsonData || '' === $jsonData || [] === $jsonData) {
            return [];
        }

        // Verwende MetaInfo Lang Fields Helper wenn verfügbar
        if (class_exists('\FriendsOfRedaxo\MetaInfoLangFields\MetainfoLangHelper')) {
            $normalized = MetainfoLangHelper::normalizeLanguageData($jsonData);
        } else {
            // Fallback: JSON selbst dekodieren
            $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;
            $normalized = is_array($data) ? $data : [];
        }

        $result = [];
        $languages = rex_clang::getAll();

        foreach ($normalized as $item) {
            if (isset($item['clang_id']) && isset($item['value'])) {
                $clangId = (int) $item['clang_id'];
                if (isset($languages[$clangId])) {
                    $langCode = $languages[$clangId]->getCode();
                    $result[$langCode] = $item['value'];
                }
            }
        }

        return $result;
    }

    /**
     * Validate if a field name is allowed for MetaInfo updates.
     */
    private function isValidMetaInfoField(string $fieldName): bool
    {
        // Get all valid MetaInfo fields from database
        static $validFields = null;

        if (null === $validFields) {
            $validFields = [];
            $sql = rex_sql::factory();
            $sql->setQuery('SELECT name FROM rex_metainfo_field WHERE table_name = "rex_media"');
            while ($sql->hasNext()) {
                $validFields[] = 'med_' . (string) ($sql->getValue('name') ?? '');
                $sql->next();
            }
        }

        return in_array($fieldName, $validFields, true);
    }

    /**
     * Sanitize a metadata value (string or array).
     */
    private function sanitizeMetaInfoValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $k => $v) {
                // Recursively sanitize for nested arrays (e.g., multilingual fields)
                $sanitized[$k] = $this->sanitizeMetaInfoValue($v);
            }
            return $sanitized;
        }
        // Sanitize string: trim, remove dangerous chars but keep basic formatting
        $sanitized = trim((string) $value);
        // Remove potential script tags and other dangerous content
        $sanitized = (string) preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $sanitized);
        $sanitized = (string) preg_replace('/javascript:/i', '', $sanitized);
        $sanitized = preg_replace('/on\w+\s*=/i', '', $sanitized);
        return $sanitized;
    }
}
