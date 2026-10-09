<?php

namespace FriendsOfRedaxo\FilePondUploader\Api;

use rex_i18n;
use FriendsOfRedaxo\FilePondUploader\Helper;
use rex;
use Exception;
use rex_api_function;
use rex_api_result;
use rex_response;
use FriendsOfRedaxo\FilePondUploader\Ai\AltTextGenerator;
use FriendsOfRedaxo\FilePondUploader\AltTextChecker;

/**
 * API Endpoint für Alt-Text-Checker.
 */
class AltChecker extends rex_api_function
{
    protected $published = false;  // Nur für eingeloggte Backend-User

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        // Berechtigung prüfen
        $user = rex::getUser();
        if (!rex::isBackend() || null === $user || (!$user->isAdmin() && !$user->hasPerm('filepond_uploader[alt_checker]'))) {
            $this->sendJson(['error' => 'Zugriff verweigert']);
        }
        if (!Helper::isValidCsrfToken()) {
            $this->sendJson(['error' => 'Invalid CSRF token']);
        }

        $action = rex_request('action', 'string');

        switch ($action) {
            case 'list':
                $this->handleList();
                break;
            case 'stats':
                $this->handleStats();
                break;
            case 'update':
                $this->handleUpdate();
                break;
            case 'bulk_update':
                $this->handleBulkUpdate();
                break;
            case 'ai_generate':
                $this->handleAiGenerate();
                break;
            default:
                $this->sendJson(['error' => 'Unbekannte Aktion']);
        }
        return new rex_api_result(true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendJson(array $data): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::sendJson($data);
        exit;
    }

    private function handleList(): void
    {
        $filterFilename = rex_request('filter_filename', 'string', '');
        $filterCategory = rex_request('filter_category', 'int', -1);

        $filters = [];
        if ('' !== $filterFilename) {
            $filters['filename'] = $filterFilename;
        }
        if ($filterCategory >= 0) {
            $filters['category_id'] = $filterCategory;
        }

        try {
            // Prüfen ob med_alt Feld existiert
            if (!AltTextChecker::checkAltFieldExists()) {
                $this->sendJson([
                    'error' => rex_i18n::rawMsg('alt_checker_field_missing_text'),
                    'field_missing' => true,
                ]);
            }

            $images = AltTextChecker::findImagesWithoutAlt($filters);
            $stats = AltTextChecker::getStatistics();

            $this->sendJson([
                'images' => $images,
                'stats' => $stats,
            ]);
        } catch (Exception $e) {
            $this->sendJson(['error' => $e->getMessage()]);
        }
    }

    private function handleStats(): void
    {
        try {
            if (!AltTextChecker::checkAltFieldExists()) {
                $this->sendJson([
                    'error' => 'Das Feld med_alt existiert nicht',
                    'field_missing' => true,
                ]);
            }

            $stats = AltTextChecker::getStatistics();
            $categories = AltTextChecker::getCategoriesWithMissingAlt();

            $this->sendJson([
                'stats' => $stats,
                'categories' => $categories,
            ]);
        } catch (Exception $e) {
            $this->sendJson(['error' => $e->getMessage()]);
        }
    }

    private function handleUpdate(): void
    {
        $filename = rex_request('filename', 'string', '');
        $altText = rex_request('alt_text', 'string', '');
        $decorative = rex_request('decorative', 'bool', false);
        $isMultilang = rex_request('is_multilang', 'bool', false);

        if ('' === $filename) {
            $this->sendJson(['error' => rex_i18n::rawMsg('filepond_err_no_filename')]);
        }

        // Dekoratives Bild: In Negativ-Liste aufnehmen
        if ($decorative) {
            $result = AltTextChecker::markAsDecorative($filename);
        } else {
            // Mehrsprachig: JSON-String zu Array konvertieren
            if ($isMultilang && '' !== $altText) {
                $altData = json_decode($altText, true);
                if (is_array($altData)) {
                    $result = AltTextChecker::updateAltText($filename, $altData);
                } else {
                    $result = AltTextChecker::updateAltText($filename, $altText);
                }
            } else {
                $result = AltTextChecker::updateAltText($filename, $altText);
            }
        }

        $this->sendJson($result);
    }

    private function handleBulkUpdate(): void
    {
        $updatesRaw = rex_request('updates', 'string', '');

        if ('' !== $updatesRaw && '[' === $updatesRaw[0]) {
            $updates = json_decode($updatesRaw, true) ?? [];
        } else {
            $updates = rex_request('updates', 'array', []);
        }

        if ([] === $updates) {
            $this->sendJson(['error' => rex_i18n::rawMsg('filepond_err_no_updates')]);
        }

        $result = AltTextChecker::bulkUpdateAltText($updates);
        $this->sendJson($result);
    }

    /**
     * AI Alt-Text für ein einzelnes Bild generieren.
     */
    private function handleAiGenerate(): void
    {
        if (!AltTextGenerator::isEnabled()) {
            $this->sendJson(['error' => 'AI Alt-Text-Generierung ist nicht aktiviert oder API-Key fehlt']);
        }

        $filename = rex_request('filename', 'string', '');
        $language = rex_request('language', 'string', 'de');
        $languages = rex_request('languages', 'array', []);

        if ('' === $filename) {
            $this->sendJson(['error' => rex_i18n::rawMsg('filepond_err_no_filename')]);
        }

        $generator = new AltTextGenerator();

        $normalizedLanguages = [];
        foreach ($languages as $languageItem) {
            if (!is_string($languageItem)) {
                continue;
            }

            $short = strtolower(substr(trim($languageItem), 0, 2));
            if (1 !== preg_match('/^[a-z]{2}$/', $short)) {
                continue;
            }

            if (!in_array($short, $normalizedLanguages, true)) {
                $normalizedLanguages[] = $short;
            }
        }

        if ([] !== $normalizedLanguages) {
            $result = $generator->generateAltTexts($filename, $normalizedLanguages);
        } else {
            $result = $generator->generateAltText($filename, $language);
        }

        $this->sendJson($result);
    }
}
