<?php

namespace FriendsOfRedaxo\FilePondUploader\Api;

use FriendsOfRedaxo\FilePondUploader\Helper;
use rex_api_exception;
use rex_backend_login;
use rex_config;
use rex_media_category;
use rex_plugin;
use rex_user;
use rex_ycom_auth;

use function in_array;
use function is_string;

/**
 * Gemeinsame Zugriffsprüfung der filepond-APIs.
 *
 * - `token`:    Request mit gültigem `api_token` (externer Client ohne Session), kein CSRF-Token nötig.
 * - `backend`:  angemeldeter Backend-User, CSRF-Token, Rechte der Medienkategorie.
 * - `frontend`: YCom-User oder API-Token aus der Session, CSRF-Token, nur signierte Kategorien und Feldgrenzen.
 */
trait AuthorizesRequests
{
    /** @var ''|'token'|'backend'|'frontend' */
    protected string $caller = '';

    protected ?rex_user $backendUser = null;

    /** Signierte Feldgrenzen des Widgets, '' bzw. 0 = nur globale Einstellungen. */
    protected string $policyTypes = '';

    protected int $policyMaxFilesizeMb = 0;

    protected function authorize(): void
    {
        $apiToken = rex_config::get('filepond_uploader', 'api_token', '');
        $apiToken = is_string($apiToken) ? $apiToken : '';

        $requestToken = rex_request('api_token', 'string', '');
        if ('' !== $requestToken) {
            if ('' === $apiToken || !hash_equals($apiToken, $requestToken)) {
                throw new rex_api_exception('Unauthorized');
            }
            $this->caller = 'token';

            return;
        }

        if (!Helper::isValidCsrfToken()) {
            throw new rex_api_exception('Invalid CSRF token');
        }

        $user = rex_backend_login::createUser();
        if (null !== $user) {
            $this->caller = 'backend';
            $this->backendUser = $user;

            return;
        }

        $sessionToken = rex_session('filepond_token', 'string', '');
        $isYcomUser = rex_plugin::get('ycom', 'auth')->isAvailable() && null !== rex_ycom_auth::getUser();
        if ($isYcomUser || ('' !== $apiToken && '' !== $sessionToken && hash_equals($apiToken, $sessionToken))) {
            $this->caller = 'frontend';

            return;
        }

        throw new rex_api_exception('Unauthorized');
    }

    /**
     * Prüft die Ziel-Kategorie eines Uploads gegen die Rolle des Aufrufers.
     */
    protected function checkCategory(int $categoryId): int
    {
        if ($categoryId < 0) {
            $categoryId = (int) rex_config::get('filepond_uploader', 'category_id', 0);
        }

        if (0 !== $categoryId && null === rex_media_category::get($categoryId)) {
            throw new rex_api_exception('Invalid category');
        }

        if ('backend' === $this->caller && !$this->backendUser?->getComplexPerm('media')->hasCategoryPerm($categoryId)) {
            throw new rex_api_exception('No permission for this media category');
        }

        // Frontend braucht immer eine Signatur, im Backend gilt sie, sobald das Widget eine mitschickt
        $signature = rex_request('category_sig', 'string', '');
        if ('frontend' === $this->caller || ('backend' === $this->caller && '' !== $signature)) {
            $types = trim(rex_request('policy_types', 'string', ''));
            $maxFilesize = max(0, rex_request('policy_maxsize', 'int', 0));
            if (!Helper::isValidUploadPolicy($categoryId, $types, $maxFilesize, $signature)) {
                throw new rex_api_exception('Invalid category');
            }
            $this->policyTypes = $types;
            $this->policyMaxFilesizeMb = $maxFilesize;
        }

        return $categoryId;
    }

    /** Merkt sich hochgeladene Dateien der Session, nur diese darf der Aufrufer wieder löschen. */
    protected function rememberUpload(string $filename): void
    {
        $this->rememberInSession('filepond_uploads', $filename, 500);
    }

    protected function isOwnUpload(string $filename): bool
    {
        return $this->isInSession('filepond_uploads', $filename);
    }

    /** Vorbereitete Uploads (fileId) der Session, nur diese darf der Aufrufer abbrechen. */
    protected function rememberPrepared(string $fileId): void
    {
        $this->rememberInSession('filepond_prepared', $fileId, 100);
    }

    protected function isOwnPrepared(string $fileId): bool
    {
        return $this->isInSession('filepond_prepared', $fileId);
    }

    private function rememberInSession(string $key, string $value, int $limit): void
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            return;
        }

        $values = rex_session($key, 'array', []);
        $values[] = $value;
        rex_set_session($key, array_slice(array_values(array_unique($values)), -$limit));
    }

    private function isInSession(string $key, string $value): bool
    {
        return PHP_SESSION_ACTIVE === session_status() && in_array($value, rex_session($key, 'array', []), true);
    }
}
