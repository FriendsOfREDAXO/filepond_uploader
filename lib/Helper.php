<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex;
use rex_addon;
use rex_config;
use rex_csrf_token;
use rex_i18n;
use rex_login;
use rex_url;
use rex_view;

use function is_bool;
use function is_string;

class Helper
{
    // Tracking variables for scripts and styles
    private static bool $scriptsIncluded = false;
    private static bool $stylesIncluded = false;

    /**
     * Get JavaScript files.
     * @return string Returns HTML string in frontend, empty string in backend after adding scripts via rex_view
     */
    public static function getScripts(): string
    {
        // Return if already included
        if (self::$scriptsIncluded) {
            return '';
        }

        $addon = rex_addon::get('filepond_uploader');

        $jsFiles = [
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-file-validate-type.min.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-file-validate-size.min.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-exif-orientation.min.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-preview.min.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-resize.min.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-transform.min.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-edit.min.js'),
            $addon->getAssetsUrl('filepond/filepond.min.js'),
            $addon->getAssetsUrl('filepond_modal.js'),
            $addon->getAssetsUrl('filepond_image_editor.js'),
            $addon->getAssetsUrl('filepond_widget.js'),
        ];

        if (rex::isBackend()) {
            $jsFiles[] = $addon->getAssetsUrl('mediapool_ai.js');
        }

        if (rex::isBackend()) {
            foreach ($jsFiles as $file) {
                rex_view::addJsFile($file);
            }
            self::$scriptsIncluded = true;
            return '';
        }

        self::$scriptsIncluded = true;
        return implode(PHP_EOL, array_map(
            static fn (string $file): string => sprintf(
                '<script type="text/javascript" src="%s?v=%s" defer></script>',
                $file,
                rex_escape($addon->getVersion()),
            ),
            $jsFiles,
        ));
    }

    /**
     * Get CSS files.
     * @return string Returns HTML string in frontend, empty string in backend after adding styles via rex_view
     */
    public static function getStyles(): string
    {
        // Return if already included
        if (self::$stylesIncluded) {
            return '';
        }

        $addon = rex_addon::get('filepond_uploader');

        $cssFiles = [
            $addon->getAssetsUrl('filepond/filepond.min.css'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-preview.min.css'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-edit.min.css'),
            $addon->getAssetsUrl('filepond_widget.css'),
            $addon->getAssetsUrl('filepond-custom-styles.css'),
            $addon->getAssetsUrl('filepond_metainfo_lang.css'),
        ];

        if (!rex::isBackend()) {
            $cssFiles[] = $addon->getAssetsUrl('filepond_frontend.css');
        }

        if (rex::isBackend()) {
            foreach ($cssFiles as $file) {
                rex_view::addCssFile($file);
            }
            self::$stylesIncluded = true;
            return '';
        }

        self::$stylesIncluded = true;
        return implode(PHP_EOL, array_map(
            static fn (string $file): string => sprintf(
                '<link rel="stylesheet" type="text/css" href="%s?v=%s">',
                $file,
                rex_escape($addon->getVersion()),
            ),
            $cssFiles,
        ));
    }

    /** Gemeinsamer CSRF-Token aller filepond-APIs (Upload, Metadaten, KI). */
    public static function csrfToken(): string
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            rex_login::startSession();
        }

        return rex_csrf_token::factory('filepond_uploader')->getValue();
    }

    /** Prüft den mitgeschickten Parameter `_csrf_token` gegen csrfToken(). */
    public static function isValidCsrfToken(): bool
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            rex_login::startSession();
        }

        return rex_csrf_token::factory('filepond_uploader')->isValid();
    }

    /** Gültigkeit einer Widget-Signatur in Sekunden (jede Seitenausgabe erzeugt eine neue). */
    public const POLICY_TTL = 86400;

    /**
     * Ablaufzeit für neue Signaturen: volle Stunde plus POLICY_TTL, innerhalb einer Stunde gleich
     * (so bleiben Seiten-Caches kurzzeitig stabil).
     */
    public static function policyExpires(): int
    {
        return (intdiv(time(), 3600) + 1) * 3600 + self::POLICY_TTL;
    }

    /**
     * Signiert Kategorie und Feldgrenzen eines Widgets. Der Server übernimmt nur signierte
     * Werte, Kategorie, Dateitypen und Größe lassen sich clientseitig nicht ändern.
     * Die Signatur gilt nur bis $expires, nur in der aktuellen Session und nur für $context
     * (z. B. "ycom" = nur für angemeldete YCom-Nutzer).
     */
    public static function signUploadPolicy(int $categoryId, string $allowedTypes = '', int $maxFilesizeMb = 0, int $expires = 0, string $context = ''): string
    {
        if ($expires <= 0) {
            $expires = self::policyExpires();
        }

        return hash_hmac('sha256', implode('|', [
            'filepond_policy_v2',
            $categoryId,
            $allowedTypes,
            $maxFilesizeMb,
            $expires,
            $context,
            self::sessionHash(),
        ]), self::secret());
    }

    public static function isValidUploadPolicy(int $categoryId, string $allowedTypes, int $maxFilesizeMb, string $signature, int $expires = 0, string $context = ''): bool
    {
        return '' !== $signature
            && $expires > time()
            && hash_equals(self::signUploadPolicy($categoryId, $allowedTypes, $maxFilesizeMb, $expires, $context), $signature);
    }

    /** Kontext, der eine YCom-Anmeldung verlangt: "ycom" oder "ycom:…". */
    public static function policyRequiresYcom(string $context): bool
    {
        return 'ycom' === $context || str_starts_with($context, 'ycom:');
    }

    /**
     * Prüft Datei gegen eine Typliste wie "image/*,application/pdf,.docx".
     */
    public static function isTypeAllowed(string $allowedTypes, string $filename, string $mimeType): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = strtolower($mimeType);

        foreach (explode(',', strtolower($allowedTypes)) as $type) {
            $type = trim($type);
            if ('' === $type) {
                continue;
            }
            if (str_starts_with($type, '.')) {
                if (substr($type, 1) === $extension) {
                    return true;
                }
            } elseif (str_ends_with($type, '/*')) {
                if (str_starts_with($mimeType, substr($type, 0, -1))) {
                    return true;
                }
            } elseif ($type === $mimeType) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ein fileId stammt immer aus uniqid('filepond_', true) und landet in Dateipfaden,
     * daher nur genau dieses Format zulassen.
     */
    public static function isValidFileId(string $fileId): bool
    {
        return 1 === preg_match('/^filepond_[0-9a-f]{13,14}\.[0-9]{1,10}$/', $fileId);
    }

    /**
     * Sicherheits-Attribute eines Upload-Widgets: CSRF-Token, signierte Kategorie und Feldgrenzen.
     * Ohne $allowedTypes/$maxFilesizeMb gelten serverseitig nur die globalen Einstellungen.
     * $context bindet die Signatur zusätzlich an einen Einsatzort (frei wählbar, z. B. Formular/Feld;
     * "ycom" bzw. "ycom:…" erlaubt Uploads nur angemeldeten YCom-Nutzern).
     */
    public static function widgetSecurityAttributes(int $categoryId, string $allowedTypes = '', int $maxFilesizeMb = 0, string $context = ''): string
    {
        $allowedTypes = trim($allowedTypes);
        $maxFilesizeMb = max(0, $maxFilesizeMb);
        $context = trim($context);
        // Token zuerst: startet die Session, an die die Signatur gebunden wird
        $csrf = self::csrfToken();
        $expires = self::policyExpires();

        return ' data-filepond-csrf="' . rex_escape($csrf) . '"'
            . ' data-filepond-cat-sig="' . rex_escape(self::signUploadPolicy($categoryId, $allowedTypes, $maxFilesizeMb, $expires, $context)) . '"'
            . ' data-filepond-policy-types="' . rex_escape($allowedTypes) . '"'
            . ' data-filepond-policy-maxsize="' . $maxFilesizeMb . '"'
            . ' data-filepond-policy-expires="' . $expires . '"'
            . ' data-filepond-policy-context="' . rex_escape($context) . '"';
    }

    /**
     * data-filepond-* Attribute aus den Einstellungen; $overrides ersetzt einzelne Werte
     * (Schlüssel ohne Präfix, z. B. 'maxfiles' => 1), null lässt ein Attribut weg.
     *
     * @param array<string, string|int|bool|null> $overrides
     */
    public static function configAttributes(array $overrides = []): string
    {
        $maxPixel = Config::int('client_max_pixel', 0);
        $quality = Config::int('client_image_quality', 0);

        $attributes = array_replace([
            'types' => Config::string('allowed_types', 'image/*,video/*,application/pdf'),
            'maxsize' => Config::int('max_filesize', 200),
            'maxfiles' => Config::int('max_files', 30),
            'lang' => rex::getUser()?->getLanguage() ?: rex_i18n::getLocale(),
            'chunk-enabled' => Config::isEnabled('enable_chunks', true),
            'chunk-size' => Config::int('chunk_size', 5) * 1024 * 1024,
            'title-required' => Config::isEnabled('title_required_default'),
            'alt-required' => Config::isEnabled('alt_required_default', true),
            'max-pixel' => $maxPixel > 0 ? $maxPixel : Config::int('max_pixel', 2100),
            'image-quality' => $quality > 0 ? $quality : Config::int('image_quality', 90),
            'client-resize' => Config::isEnabled('create_thumbnails', true),
            'image-editor' => Config::isEnabled('enable_image_editor', true),
            // KI-Vorschläge nur im Backend, die KI-API nimmt nur Backend-User an
            'ai-enabled' => rex::isBackend() && Config::isEnabled('enable_ai_alt') && Config::isEnabled('enable_ai_upload_modal', true),
            'ai-target-field' => Config::string('ai_target_field', 'med_alt'),
            'media-url' => rex_url::media(),
        ], $overrides);

        $html = '';
        foreach ($attributes as $name => $value) {
            if (null === $value) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $html .= ' data-filepond-' . $name . '="' . rex_escape((string) $value) . '"';
        }

        return $html;
    }

    /** Schaltet den Bildeditor am Widget gemäß Einstellung ein oder aus. */
    public static function imageEditorAttribute(): string
    {
        return ' data-filepond-image-editor="' . (Config::isEnabled('enable_image_editor', true) ? 'true' : 'false') . '"';
    }

    /** Eigener Signaturschlüssel (nicht der API-Token), wird bei Bedarf erzeugt. */
    private static function secret(): string
    {
        $secret = rex_config::get('filepond_uploader', 'policy_secret', '');
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            rex_config::set('filepond_uploader', 'policy_secret', $secret);
        }

        return $secret;
    }

    /** Bindet Signaturen an die Session; ohne Session leer. */
    private static function sessionHash(): string
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            rex_login::startSession();
        }
        $id = session_id();

        return false === $id || '' === $id ? '' : hash('sha256', 'filepond_session|' . $id);
    }
}
