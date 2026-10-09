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
use function is_array;
use function is_string;

/**
 * Gemeinsame Zugriffsprüfung der filepond-APIs.
 *
 * - `token`:    Request mit gültigem `api_token` (externer Client ohne Session), kein CSRF-Token nötig.
 * - `backend`:  angemeldeter Backend-User, CSRF-Token, Rechte der Medienkategorie.
 * - `frontend`: YCom-User oder API-Token aus der Session, CSRF-Token, nur signierte Kategorien.
 */
trait AuthorizesRequests
{
    /** @var ''|'token'|'backend'|'frontend' */
    protected string $caller = '';

    protected ?rex_user $backendUser = null;

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

        if ('frontend' === $this->caller && !Helper::isValidCategorySignature($categoryId, rex_request('category_sig', 'string', ''))) {
            throw new rex_api_exception('Invalid category');
        }

        return $categoryId;
    }

    /** Merkt sich hochgeladene Dateien der Session, nur diese darf der Aufrufer wieder löschen. */
    protected function rememberUpload(string $filename): void
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            return;
        }

        $uploads = rex_session('filepond_uploads', 'array', []);
        $uploads[] = $filename;
        rex_set_session('filepond_uploads', array_slice(array_values(array_unique($uploads)), -500));
    }

    protected function isOwnUpload(string $filename): bool
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            return false;
        }

        $uploads = rex_session('filepond_uploads', 'array', []);

        return is_array($uploads) && in_array($filename, $uploads, true);
    }
}
