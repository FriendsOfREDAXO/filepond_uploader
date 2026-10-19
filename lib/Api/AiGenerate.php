<?php

namespace FriendsOfRedaxo\FilePondUploader\Api;

use Throwable;
use Exception;
use rex_api_function;
use rex_api_result;
use rex_backend_login;
use rex_config;
use rex_dir;
use rex_i18n;
use rex_logger;
use rex_path;
use rex_plugin;
use rex_request;
use rex_response;
use rex_user;
use rex_ycom_auth;
use FriendsOfRedaxo\FilePondUploader\Ai\AltTextGenerator;
use FriendsOfRedaxo\FilePondUploader\AltTextChecker;
use FriendsOfRedaxo\FilePondUploader\Config;

/**
 * API Endpoint für AI Alt-Text Generierung.
 *
 * @package filepond_uploader
 */

class AiGenerate extends rex_api_function
{
    use AuthorizesRequests;

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
            if ([] === $files) {
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
    private function sendJson(array $data, string $status = rex_response::HTTP_OK): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus($status);
        rex_response::sendJson($data);
        exit;
    }

    /** KI-Vorschläge: Admins und Benutzer mit Upload- oder Alt-Text-Checker-Recht. */
    public static function userMayUseAi(rex_user $user): bool
    {
        return $user->isAdmin() || $user->hasPerm('filepond_uploader[upload]') || $user->hasPerm('filepond_uploader[alt_checker]');
    }

    /**
     * Einfaches Limit je Benutzer: höchstens `ai_rate_limit` Anfragen (Standard 100) in 10 Minuten.
     * Gibt false zurück, wenn das Limit erreicht ist.
     */
    public static function consumeRateLimit(rex_user $user): bool
    {
        $limit = Config::int('ai_rate_limit', 100);
        if ($limit <= 0) {
            $limit = 100;
        }
        $window = 600;

        $dir = rex_path::addonCache('filepond_uploader', 'ai_rate');
        if (!rex_dir::create($dir)) {
            return true;
        }
        $handle = @fopen($dir . '/' . $user->getId() . '.json', 'c+');
        if (false === $handle) {
            return true;
        }

        try {
            flock($handle, LOCK_EX);
            $stamps = json_decode((string) stream_get_contents($handle), true);
            $since = time() - $window;
            $stamps = array_values(array_filter(is_array($stamps) ? $stamps : [], static fn ($t): bool => is_int($t) && $t > $since));
            if (count($stamps) >= $limit) {
                return false;
            }
            $stamps[] = time();
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($stamps));

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function execute(): rex_api_result
    {
        // KI-Anfragen kosten Geld bzw. Rechenzeit: nur für Backend-User
        try {
            $this->authorize();
        } catch (Throwable) {
            $this->sendJson(['success' => false, 'error' => 'Unauthorized'], rex_response::HTTP_UNAUTHORIZED);
        }
        if ('backend' !== $this->caller || null === $this->backendUser || !self::userMayUseAi($this->backendUser)) {
            $this->sendJson(['success' => false, 'error' => 'Unauthorized'], rex_response::HTTP_FORBIDDEN);
        }

        $mediaName = rex_request('media_name', 'string', '');
        if ('' !== $mediaName && !AltTextChecker::userMayEdit($this->backendUser, $mediaName)) {
            $this->sendJson(['success' => false, 'error' => 'No permission for this media category'], rex_response::HTTP_FORBIDDEN);
        }

        // Prüfen ob AI aktiviert ist
        if (!AltTextGenerator::isEnabled()) {
            $this->sendJson(['success' => false, 'error' => 'AI generation is disabled'], rex_response::HTTP_FORBIDDEN);
        }
        if (!self::consumeRateLimit($this->backendUser)) {
            $this->sendJson(['success' => false, 'error' => rex_i18n::rawMsg('filepond_ai_rate_limited')], '429 Too Many Requests');
        }

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
            rex_logger::logException($e);
            $result = ['success' => false, 'error' => rex_i18n::rawMsg('filepond_error_unknown')];
        }

        $this->sendJson($result);
    }
}
