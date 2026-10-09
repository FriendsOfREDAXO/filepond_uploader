<?php

namespace FriendsOfRedaxo\FilePondUploader\Api;

use rex_api_function;
use rex_api_result;
use rex_backend_login;
use rex_config;
use rex_path;
use rex_plugin;
use rex_request;
use rex_response;
use rex_ycom_auth;
use FriendsOfRedaxo\FilePondUploader\Ai\AltTextGenerator;

/**
 * API Endpoint für AI Alt-Text Generierung.
 *
 * @package filepond_uploader
 */

class AiGenerate extends rex_api_function
{
    protected $published = true;

    /**
     * Urspruenglicher Name der hochgeladenen Datei (fuer {filename} im Prompt).
     */
    private function getUploadedFileName(): string
    {
        $name = rex_request('file_name', 'string', '');
        if ('' === $name) {
            foreach ($_FILES as $fileInfo) {
                if (is_array($fileInfo) && isset($fileInfo['name']) && is_string($fileInfo['name'])) {
                    $name = $fileInfo['name'];
                    break;
                }
            }
        }

        return basename(str_replace('\\', '/', $name));
    }

    /**
     * @return array{path: string, error: string|null}
     */
    private function getUploadedFilePath(): array
    {
        $candidateFields = ['file', 'upload', 'filepond', 'image'];

        foreach ($candidateFields as $field) {
            $files = rex_request::files($field, 'array', []);
            if (!is_array($files) || [] === $files) {
                continue;
            }

            // Standardstruktur: ['tmp_name' => '/tmp/...', 'error' => 0, ...]
            if (isset($files['tmp_name']) && is_string($files['tmp_name']) && '' !== $files['tmp_name']) {
                $errorCode = isset($files['error']) && is_int($files['error']) ? $files['error'] : UPLOAD_ERR_OK;
                if (UPLOAD_ERR_OK !== $errorCode) {
                    return ['path' => '', 'error' => 'Upload error code: ' . $errorCode . ' (field: ' . $field . ')'];
                }
                return ['path' => $files['tmp_name'], 'error' => null];
            }

            // Mehrfachstruktur: ['tmp_name' => ['...'], 'error' => [0], ...]
            if (isset($files['tmp_name']) && is_array($files['tmp_name'])) {
                $tmpNames = $files['tmp_name'];
                $errors = isset($files['error']) && is_array($files['error']) ? $files['error'] : [];

                foreach ($tmpNames as $idx => $tmpName) {
                    if (!is_string($tmpName) || '' === $tmpName) {
                        continue;
                    }
                    $errorCode = isset($errors[$idx]) && is_int($errors[$idx]) ? $errors[$idx] : UPLOAD_ERR_OK;
                    if (UPLOAD_ERR_OK !== $errorCode) {
                        return ['path' => '', 'error' => 'Upload error code: ' . $errorCode . ' (field: ' . $field . ')'];
                    }
                    return ['path' => $tmpName, 'error' => null];
                }
            }
        }

        // Fallback: direktes Durchsuchen von $_FILES (defensiv bei exotischen Feldnamen)
        foreach ($_FILES as $field => $fileInfo) {
            if (!is_array($fileInfo)) {
                continue;
            }

            if (isset($fileInfo['tmp_name']) && is_string($fileInfo['tmp_name']) && '' !== $fileInfo['tmp_name']) {
                $errorCode = isset($fileInfo['error']) && is_int($fileInfo['error']) ? $fileInfo['error'] : UPLOAD_ERR_OK;
                if (UPLOAD_ERR_OK !== $errorCode) {
                    return ['path' => '', 'error' => 'Upload error code: ' . $errorCode . ' (field: ' . (string) $field . ')'];
                }
                return ['path' => $fileInfo['tmp_name'], 'error' => null];
            }

            if (isset($fileInfo['tmp_name']) && is_array($fileInfo['tmp_name'])) {
                $tmpNames = $fileInfo['tmp_name'];
                $errors = isset($fileInfo['error']) && is_array($fileInfo['error']) ? $fileInfo['error'] : [];
                foreach ($tmpNames as $idx => $tmpName) {
                    if (!is_string($tmpName) || '' === $tmpName) {
                        continue;
                    }
                    $errorCode = isset($errors[$idx]) && is_int($errors[$idx]) ? $errors[$idx] : UPLOAD_ERR_OK;
                    if (UPLOAD_ERR_OK !== $errorCode) {
                        return ['path' => '', 'error' => 'Upload error code: ' . $errorCode . ' (field: ' . (string) $field . ')'];
                    }
                    return ['path' => $tmpName, 'error' => null];
                }
            }
        }

        return ['path' => '', 'error' => 'No uploaded file found in request'];
    }

    /**
     * @param array<string, mixed> $data
     * @return never
     */
    private function sendJson(array $data, int $statusCode = 200): never
    {
        rex_response::cleanOutputBuffers();
        if (200 !== $statusCode) {
            rex_response::setStatus($statusCode);
        }
        rex_response::sendJson($data);
        exit;
    }

    private function isAuthorized(): bool
    {
        // Backend User Check
        $isBackendUser = null !== rex_backend_login::createUser();

        // Token Check (Request oder Session)
        $apiToken = rex_config::get('filepond_uploader', 'api_token');
        $apiTokenStr = is_string($apiToken) ? $apiToken : '';
        $requestToken = rex_request('api_token', 'string', '');
        $sessionToken = rex_session('filepond_token', 'string', '');

        $isValidToken = ('' !== $apiTokenStr && '' !== $requestToken && hash_equals($apiTokenStr, $requestToken))
            || ('' !== $apiTokenStr && '' !== $sessionToken && hash_equals($apiTokenStr, $sessionToken));

        // YCom User Check
        $isYComUser = false;
        if (rex_plugin::get('ycom', 'auth')->isAvailable()) {
            /** @phpstan-ignore class.notFound */
            if (null !== rex_ycom_auth::getUser()) {
                $isYComUser = true;
            }
        }

        return $isBackendUser || $isValidToken || $isYComUser;
    }

    public function execute(): rex_api_result
    {
        // Berechtigung prüfen
        if (!$this->isAuthorized()) {
            $this->sendJson(['success' => false, 'error' => 'Unauthorized'], rex_response::HTTP_UNAUTHORIZED);
        }

        // Prüfen ob AI aktiviert ist
        if (!AltTextGenerator::isEnabled()) {
            $this->sendJson(['success' => false, 'error' => 'AI generation is disabled'], rex_response::HTTP_FORBIDDEN);
        }

        $fileId = rex_request('file_id', 'string', '');
        $mediaName = rex_request('media_name', 'string', '');
        $language = rex_request('language', 'string', 'de');
        $languagesRaw = rex_request('languages', 'array', []);

        $languages = [];
        foreach ($languagesRaw as $languageItem) {
            if (!is_string($languageItem)) {
                continue;
            }
            $trimmed = trim($languageItem);
            if ('' === $trimmed) {
                continue;
            }
            $short = strtolower(substr($trimmed, 0, 2));
            if (!preg_match('/^[a-z]{2}$/', $short)) {
                continue;
            }
            if (!in_array($short, $languages, true)) {
                $languages[] = $short;
            }
        }

        $generator = new AltTextGenerator();
        // Erneutes Erzeugen fuer bereits befuellte Felder: Ergebnis-Cache ueberspringen.
        $generator->setForceRefresh(rex_request('regenerate', 'bool', false));
        $result = ['success' => false, 'error' => 'Unknown error'];

        try {
            // Fall 1: Existierendes Bild im Medienpool
            if ('' !== $mediaName) {
                if ([] !== $languages) {
                    $result = $generator->generateAltTexts($mediaName, $languages);
                } else {
                    $result = $generator->generateAltText($mediaName, $language);
                }
            }
            // Fall 2: Temporärer Upload (FilePond)
            else {
                $filePath = '';

                // 1) Direkter Upload im Request (Client-seitig)
                $uploaded = $this->getUploadedFilePath();
                if ('' !== $uploaded['path']) {
                    $filePath = $uploaded['path'];
                }
                // 2) Bereits vorbereitete temporäre Datei per file_id (Server-seitig)
                elseif ('' !== $fileId) {
                    $baseDir = rex_path::addonData('filepond_uploader', 'upload');
                    $filePath = $baseDir . $fileId;
                }

                if ('' !== $filePath) {
                    if ([] !== $languages) {
                        $result = $generator->generateAltTextsFromPath($filePath, $languages);
                    } else {
                        $result = $generator->generateAltTextFromPath($filePath, $language, $this->getUploadedFileName());
                    }
                } else {
                    $errorMessage = null !== $uploaded['error'] ? $uploaded['error'] : 'No file provided';
                    $result = ['success' => false, 'error' => $errorMessage];
                }
            }
        } catch (Exception $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        $this->sendJson($result);
    }
}
