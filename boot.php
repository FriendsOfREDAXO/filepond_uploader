<?php

use FriendsOfRedaxo\FilePondUploader\Ai\AltTextGenerator;
use FriendsOfRedaxo\FilePondUploader\AltTextChecker;
use FriendsOfRedaxo\FilePondUploader\Api\AiGenerate;
use FriendsOfRedaxo\FilePondUploader\Api\AltChecker;
use FriendsOfRedaxo\FilePondUploader\Api\AutoMetainfo;
use FriendsOfRedaxo\FilePondUploader\Api\Upload;
use FriendsOfRedaxo\FilePondUploader\Api\YcomAuth;
use FriendsOfRedaxo\FilePondUploader\Helper;
use FriendsOfRedaxo\FilePondUploader\InfoCenter\UploadWidget;
use FriendsOfRedaxo\FilePondUploader\LangFormatter;
use FriendsOfRedaxo\FilePondUploader\MediaCleanup;
use FriendsOfRedaxo\FilePondUploader\YcomAuthSettings;

/** @var rex_addon $this */

// Klassennamen bis 2.x: weiter nutzbar, aufgelöst erst bei Bedarf.
spl_autoload_register(static function (string $class): void {
    $class = ltrim($class, '\\');
    $alias = [
        'filepond_helper' => Helper::class,
        'filepond_lang_formatter' => LangFormatter::class,
        'filepond_alt_text_checker' => AltTextChecker::class,
        'filepond_ai_alt_generator' => AltTextGenerator::class,
        'rex_api_filepond_uploader' => Upload::class,
        'rex_api_filepond_ai_generate' => AiGenerate::class,
        'rex_api_filepond_alt_checker' => AltChecker::class,
        'rex_api_filepond_auto_metainfo' => AutoMetainfo::class,
        'rex_api_filepond_ycom_auth' => YcomAuth::class,
        'friendsofredaxo\\filepond\\filepondmediacleanup' => MediaCleanup::class,
        'friendsofredaxo\\filepond\\ycomauthsettings' => YcomAuthSettings::class,
        'klxm\\infocenter\\widgets\\fileponduploadwidget' => UploadWidget::class,
    ][strtolower($class)] ?? null;
    if (null !== $alias && class_exists($alias)) {
        class_alias($alias, $class);
    }
});

rex_api_function::register('filepond_uploader', Upload::class);
rex_api_function::register('filepond_ai_generate', AiGenerate::class);
rex_api_function::register('filepond_alt_checker', AltChecker::class);
rex_api_function::register('filepond_auto_metainfo', AutoMetainfo::class);
rex_api_function::register('filepond_ycom_auth', YcomAuth::class);

rex_yform::addTemplatePath($this->getPath('ytemplates'));

// MEDIA_IS_IN_USE Extension Point registrieren für bessere Kontrolle
rex_extension::register('MEDIA_IS_IN_USE', [MediaCleanup::class, 'isMediaInUse']);

// MediaPlace-Upload-Anbieter (siehe UploadProviderRegistry/MP.registerUploadProvider()
// im mediaplace-Addon): rein soft-optional, komplett wirkungslos ohne installiertes
// MediaPlace, da MEDIAPLACE_UPLOAD_PROVIDERS ausschliesslich von dessen eigenem Code
// abgefragt wird. Label/Recht hier, die eigentliche Uebernahme passiert clientseitig
// in assets/mediaplace_upload_provider.js. Registrierung bewusst erst in
// PACKAGES_INCLUDED (nicht direkt hier auf oberster Ebene) -- gleiches Muster wie die
// bestehende info_center-Integration weiter unten: stellt sicher, dass ALLE Addons
// (auch mediaplace selbst, falls dessen boot.php spaeter als dieses hier laeuft)
// bereits vollstaendig gebootet sind, bevor die Registrierung greift.
rex_perm::register('filepond_uploader[mediaplace_upload]', 'MediaPlace-Upload-Anbieter: Upload-Button/Drag&Drop durch den FilePond-Dialog ersetzen');
rex_extension::register('PACKAGES_INCLUDED', static function () {
    rex_extension::register('MEDIAPLACE_UPLOAD_PROVIDERS', static function (rex_extension_point $ep) {
        $providers = $ep->getSubject();
        $providers['filepond'] = [
            'label' => 'FilePond',
            'perm' => 'filepond_uploader[mediaplace_upload]',
        ];

        return $providers;
    });
});

// Mediapool MIME-Types erweitern für Typen, die FilePond erlaubt aber der Mediapool nicht kennt
if (rex_addon::get('mediapool')->isAvailable()) {
    $filepondMimeMap = [
        // Bilder
        'image/jpeg' => ['ext' => 'jpg', 'alt' => ['image/pjpeg']],
        'image/png' => ['ext' => 'png'],
        'image/gif' => ['ext' => 'gif'],
        'image/webp' => ['ext' => 'webp'],
        'image/svg+xml' => ['ext' => 'svg'],
        'image/tiff' => ['ext' => 'tiff'],
        'image/bmp' => ['ext' => 'bmp'],
        'image/heic' => ['ext' => 'heic'],
        'image/avif' => ['ext' => 'avif'],
        'image/x-icon' => ['ext' => 'ico', 'alt' => ['image/vnd.microsoft.icon']],

        // Dokumente
        'application/pdf' => ['ext' => 'pdf'],
        'text/plain' => ['ext' => 'txt', 'alt' => ['application/octet-stream']],
        'text/csv' => ['ext' => 'csv', 'alt' => ['text/plain', 'application/octet-stream']],
        'text/calendar' => ['ext' => 'ics', 'alt' => ['text/plain', 'application/octet-stream']],
        'text/x-vcalendar' => ['ext' => 'vcal', 'alt' => ['text/calendar', 'text/plain', 'application/octet-stream']],
        'text/vcard' => ['ext' => 'vcf', 'alt' => ['text/x-vcard', 'text/plain', 'application/octet-stream']],
        'text/markdown' => ['ext' => 'md', 'alt' => ['text/plain', 'application/octet-stream']],
        'application/rtf' => ['ext' => 'rtf'],
        'application/json' => ['ext' => 'json', 'alt' => ['text/plain']],
        'text/xml' => ['ext' => 'xml', 'alt' => ['application/xml']],
        'text/vtt' => ['ext' => 'vtt'],
        'text/srt' => ['ext' => 'srt', 'alt' => ['text/plain']],

        // Archive
        'application/zip' => ['ext' => 'zip', 'alt' => ['application/x-zip-compressed']],
        'application/x-gzip' => ['ext' => 'gz', 'alt' => ['application/gzip']],
        'application/x-tar' => ['ext' => 'tar'],
        'application/x-rar-compressed' => ['ext' => 'rar', 'alt' => ['application/vnd.rar']],
        'application/x-7z-compressed' => ['ext' => '7z'],

        // Video
        'video/mp4' => ['ext' => 'mp4'],
        'video/mpeg' => ['ext' => 'mpeg'],
        'video/quicktime' => ['ext' => 'mov'],
        'video/webm' => ['ext' => 'webm'],
        'video/ogg' => ['ext' => 'ogv'],
        'video/x-msvideo' => ['ext' => 'avi'],
        'video/x-matroska' => ['ext' => 'mkv'],

        // Audio
        'audio/mpeg' => ['ext' => 'mp3'],
        'audio/wav' => ['ext' => 'wav', 'alt' => ['audio/x-wav']],
        'audio/ogg' => ['ext' => 'ogg'],
        'audio/aac' => ['ext' => 'aac'],
        'audio/midi' => ['ext' => 'midi', 'alt' => ['audio/x-midi']],
        'audio/flac' => ['ext' => 'flac'],
        'audio/mp4' => ['ext' => 'm4a'],
        'audio/webm' => ['ext' => 'weba'],

        // Office (Modern)
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['ext' => 'docx', 'alt' => ['application/octet-stream', 'application/encrypted']],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['ext' => 'xlsx', 'alt' => ['application/octet-stream', 'application/encrypted']],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['ext' => 'pptx'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template' => ['ext' => 'dotx', 'alt' => ['application/octet-stream']],
        'application/vnd.openxmlformats-officedocument.presentationml.template' => ['ext' => 'potx'],
        'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => ['ext' => 'ppsx'],

        // Office (Legacy)
        'application/msword' => ['ext' => 'doc', 'alt' => ['application/octet-stream', 'application/encrypted']],
        'application/vnd.ms-excel' => ['ext' => 'xls', 'alt' => ['application/octet-stream', 'application/encrypted']],
        'application/vnd.ms-powerpoint' => ['ext' => 'ppt'],

        // OpenDocument
        'application/vnd.oasis.opendocument.text' => ['ext' => 'odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ext' => 'ods'],
        'application/vnd.oasis.opendocument.presentation' => ['ext' => 'odp'],

        // Fonts
        'font/woff' => ['ext' => 'woff', 'alt' => ['application/font-woff']],
        'font/woff2' => ['ext' => 'woff2'],
        'font/ttf' => ['ext' => 'ttf', 'alt' => ['application/x-font-ttf']],
        'font/otf' => ['ext' => 'otf', 'alt' => ['application/x-font-opentype']],

        // Sonstige
        'application/postscript' => ['ext' => 'eps'],
        'application/epub+zip' => ['ext' => 'epub'],
    ];

    $allowedTypes = rex_config::get('filepond_uploader', 'allowed_types', '');
    if ('' !== $allowedTypes) {
        // FilePond nutzt Komma-getrennte MIME-Types, kann aber auch Wildcards (image/*) und Endungen (.pdf) enthalten
        $configuredTypes = array_map('trim', explode(',', $allowedTypes));
        $mediapoolMimes = rex_addon::get('mediapool')->getProperty('allowed_mime_types', []);
        $changed = false;

        foreach ($configuredTypes as $type) {
            // Wildcard-Typen wie "image/*" oder "video/*" auflösen
            if (str_contains($type, '/*')) {
                $prefix = explode('/*', $type)[0] . '/';
                foreach ($filepondMimeMap as $mime => $info) {
                    if (str_starts_with($mime, $prefix) && !isset($mediapoolMimes[$info['ext']])) {
                        $mediapoolMimes[$info['ext']] = array_merge([$mime], $info['alt'] ?? []);
                        $changed = true;
                    }
                }
            } elseif (isset($filepondMimeMap[$type])) {
                // Exakter MIME-Type
                $ext = $filepondMimeMap[$type]['ext'];
                if (!isset($mediapoolMimes[$ext])) {
                    $mediapoolMimes[$ext] = array_merge([$type], $filepondMimeMap[$type]['alt'] ?? []);
                    $changed = true;
                }
            } elseif (str_starts_with($type, '.')) {
                // Dateiendung wie ".pdf", ".docx" – passenden MIME-Type finden
                $extLookup = ltrim($type, '.');
                foreach ($filepondMimeMap as $mime => $info) {
                    if ($info['ext'] === $extLookup && !isset($mediapoolMimes[$extLookup])) {
                        $mediapoolMimes[$extLookup] = array_merge([$mime], $info['alt'] ?? []);
                        $changed = true;
                        break;
                    }
                }
            }
        }

        if ($changed) {
            rex_addon::get('mediapool')->setProperty('allowed_mime_types', $mediapoolMimes);
        }
    }
}

if (rex::isBackend() && rex::getUser()) {
    // Einbindung über Static-Properties sicherstellen
    static $filepondScriptsLoaded = false;
    
    if (!$filepondScriptsLoaded) {
        Helper::getStyles();
        Helper::getScripts();
        $filepondScriptsLoaded = true;
    }

    // KI-Button-Konfiguration fuer mediapool_ai.js direkt in die Seite (rex.filepond_ai),
    // statt sie auf jeder Backend-Seite per eigenem Request nachzuladen.
    rex_view::setJsProperty('filepond_ai', AutoMetainfo::getAiButtonConfig());

    // Settings-Seite: JS für Dateitypen-Auswahl
    if ('filepond_uploader/settings/upload' === rex_be_controller::getCurrentPage()) {
        rex_view::addJsFile($this->getAssetsUrl('filepond_settings.js'));
    }

    // YCom Media Auth Defaults JS auf den Upload-Seiten laden
    $currentPage = rex_be_controller::getCurrentPage();
    $isFilePondUploadPage = in_array($currentPage, [
        'filepond_uploader/upload',
        'mediapool/upload',
        'mediapool/filepond_multiupload',
    ], true);
    if ($isFilePondUploadPage
        && YcomAuthSettings::isEnabled()
        && YcomAuthSettings::userMayManage(rex::getUser())
    ) {
        rex_view::addJsFile($this->getAssetsUrl('filepond_ycom_auth.js'));
    }

    // Verstecktes, dauerhaftes FilePond-Widget fuer den MediaPlace-Upload-
    // Anbieter (siehe assets/mediaplace_upload_provider.js): auf JEDER
    // Backend-Seite vorhanden (initFilePond()'s eigener rex:ready/
    // DOMContentLoaded-Scan initialisiert es wie jedes andere data-widget=
    // "filepond"-Element automatisch, kein manuelles filepond:init noetig),
    // damit die FilePond-Instanz beim Klick auf MediaPlace's Upload-Button/
    // Drag&Drop bereits bereitsteht -- data-filepond-cat wird dort erst zur
    // Laufzeit passend zur MediaPlace-Zielkategorie gesetzt (siehe
    // pages/upload.php fuer dasselbe Attribut-Set als Vorbild). Rein
    // clientseitig unsichtbar (Wrapper-Div traegt display:none inline,
    // siehe $inject unten), damit auf Seiten ohne MediaPlace-Nutzung kein
    // leeres FilePond-Panel auftaucht.
    rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): void {
        $subject = $ep->getSubject();
        if (!is_string($subject)) {
            return;
        }

        $cfgAllowedTypes = rex_config::get('filepond_uploader', 'allowed_types', 'image/*,video/*,.pdf,.doc,.docx,.txt');
        $dataAllowedTypes = is_string($cfgAllowedTypes) ? $cfgAllowedTypes : 'image/*,video/*,.pdf,.doc,.docx,.txt';
        $cfgMaxFilesize = rex_config::get('filepond_uploader', 'max_filesize', 10);
        $dataMaxFilesize = is_numeric($cfgMaxFilesize) ? (string) (int) $cfgMaxFilesize : '10';
        $cfgClientMaxPixel = rex_config::get('filepond_uploader', 'client_max_pixel', '');
        $cfgMaxPixel = rex_config::get('filepond_uploader', 'max_pixel', 2100);
        $dataMaxPixel = is_scalar($cfgClientMaxPixel) && '' !== $cfgClientMaxPixel ? (string) $cfgClientMaxPixel : (is_numeric($cfgMaxPixel) ? (string) (int) $cfgMaxPixel : '2100');
        $cfgClientQuality = rex_config::get('filepond_uploader', 'client_image_quality', '');
        $cfgQuality = rex_config::get('filepond_uploader', 'image_quality', 90);
        $dataQuality = is_scalar($cfgClientQuality) && '' !== $cfgClientQuality ? (string) $cfgClientQuality : (is_numeric($cfgQuality) ? (string) (int) $cfgQuality : '90');
        $cfgCreateThumbnails = rex_config::get('filepond_uploader', 'create_thumbnails', '');
        $dataClientResize = (is_string($cfgCreateThumbnails) && '|1|' === $cfgCreateThumbnails) ? 'true' : 'false';
        $isEnabledConfig = static function (string $key, bool $default): bool {
            $raw = rex_config::get('filepond_uploader', $key, $default ? '1' : '0');

            return in_array($raw, [1, '1', true, 'true', '|1|'], true);
        };
        $titleRequired = $isEnabledConfig('title_required_default', false);
        $altRequired = $isEnabledConfig('alt_required_default', true);
        $currentUser = rex::getUser();
        $langCode = $currentUser ? $currentUser->getLanguage() : 'en_gb';

        $inject = '<div id="filepond-mp3-upload-provider-wrap" style="display:none">'
            . '<input type="file" multiple'
            . ' id="filepond-mp3-upload-provider"'
            . ' data-widget="filepond"'
            . ' data-filepond-cat="0"'
            . ' data-filepond-types="' . rex_escape($dataAllowedTypes) . '"'
            . ' data-filepond-maxsize="' . rex_escape($dataMaxFilesize) . '"'
            . ' data-filepond-lang="' . rex_escape($langCode) . '"'
            . ' data-filepond-skip-meta="false"'
            . ' data-filepond-delayed-upload="false"'
            . ' data-filepond-title-required="' . ($titleRequired ? 'true' : 'false') . '"'
            . ' data-filepond-alt-required="' . ($altRequired ? 'true' : 'false') . '"'
            . ' data-filepond-max-pixel="' . rex_escape($dataMaxPixel) . '"'
            . ' data-filepond-image-quality="' . rex_escape($dataQuality) . '"'
            . ' data-filepond-client-resize="' . $dataClientResize . '"'
            . ' />'
            . '</div>';

        // Nur das LETZTE '</body>' ersetzen (gleiche Begruendung wie im
        // mediaplace-Addon: ein einfaches str_replace koennte ein zufaellig
        // gleichlautendes Vorkommen in einem Inline-Script/-Kommentar treffen).
        $lastBodyPos = strrpos($subject, '</body>');
        if (false !== $lastBodyPos) {
            $subject = substr_replace($subject, $inject . "\n" . '</body>', $lastBodyPos, strlen('</body>'));
        }

        $ep->setSubject($subject);
    });
}

// Backend-Permission für YCom-Media-Auth-Defaults registrieren
rex_perm::register(
    YcomAuthSettings::PERM,
    rex_i18n::msg('filepond_perm_ycom_media_auth')
);

// API-Endpoint zum Speichern der Session-Defaults explizit registrieren
// (defensiv – sicherstellt, dass `?rex-api-call=filepond_ycom_auth` immer auflöst,
// auch wenn der Autoload-Cache nach Neuinstallation noch nicht aktualisiert wurde).



if(rex_config::get('filepond_uploader', 'replace_mediapool', false))
{    
    rex_extension::register('PAGES_PREPARED', function (rex_extension_point $ep) {
        /** @var array<string, rex_be_page> $pages */
        $pages = $ep->getSubject();
        
        if (isset($pages['mediapool'])) {
            $mediapoolPage = $pages['mediapool'];
            if ($uploadPage = $mediapoolPage->getSubpage('upload')) {
                // Nur das subPath ändern, der Rest bleibt gleich
                $uploadPage->setSubPath(
                    rex_path::addon('filepond_uploader', 'pages/upload.php')
                );
            }
        }
    });
}

if (rex::isBackend() && rex::getUser() && rex_config::get('filepond_uploader', 'enable_mediapool_replace', true)) {
    $addon = $this;

    rex_extension::register('MEDIA_DETAIL_SIDEBAR', static function (rex_extension_point $ep) use ($addon) {
        $mediaRow = $ep->getParam('media');
        if (!$mediaRow instanceof rex_sql) {
            return $ep->getSubject();
        }

        $fileId = (int) $ep->getParam('id', 0);
        if ($fileId <= 0) {
            return $ep->getSubject();
        }

        $filename = (string) $mediaRow->getValue('filename');
        if ('' === $filename) {
            return $ep->getSubject();
        }

        $media = rex_media::get($filename);
        if (null === $media) {
            return $ep->getSubject();
        }

        $user = rex::getUser();
        if (!$user || !$user->getComplexPerm('media')->hasCategoryPerm($media->getCategoryId())) {
            return $ep->getSubject();
        }

        $ext = '.' . strtolower((string) rex_file::extension($filename));
        $allowedTypes = $ext;
        if ('.jpg' === $ext || '.jpeg' === $ext) {
            $allowedTypes = '.jpg,.jpeg';
        }

        $inputId = 'filepond-mediapool-replace-' . $fileId;
        $redirectUrl = rex_url::backendPage('mediapool/media', [
            'file_id' => $fileId,
            'info' => rex_i18n::msg('pool_file_infos_updated'),
        ], false);

        $body = '<div id="filepond-mediapool-replace">'
            . '<p class="text-muted filepond-mediapool-replace-note">'
            . rex_escape($addon->i18n('filepond_mediapool_replace_notice'))
            . '</p>'
            . '<p class="filepond-mediapool-replace-current">'
            . '<strong>' . rex_escape($addon->i18n('filepond_mediapool_replace_current_file')) . ':</strong> '
            . '<span class="rex-word-break">' . rex_escape($filename) . '</span>'
            . '</p>'
            . '<input type="hidden"'
            . ' id="' . rex_escape($inputId) . '"'
            . ' data-widget="filepond"'
            . ' data-filepond-cat="' . (int) $media->getCategoryId() . '"'
            . ' data-filepond-maxfiles="1"'
            . ' data-filepond-types="' . rex_escape($allowedTypes) . '"'
            . ' data-filepond-maxsize="' . (int) rex_config::get('filepond_uploader', 'max_filesize', 200) . '"'
            . ' data-filepond-lang="' . rex_escape((string) rex::getUser()?->getLanguage()) . '"'
            . ' data-filepond-skip-meta="true"'
            . ' data-filepond-delayed-upload="false"'
            . ' data-filepond-title-required="false"'
            . ' data-filepond-alt-required="false"'
            . ' data-filepond-chunk-enabled="' . (rex_config::get('filepond_uploader', 'enable_chunks', true) ? 'true' : 'false') . '"'
            . ' data-filepond-chunk-size="' . ((int) rex_config::get('filepond_uploader', 'chunk_size', 5) * 1024 * 1024) . '"'
            . ' data-filepond-replace-file-id="' . $fileId . '"'
            . ' data-filepond-reload-on-success="true"'
            . ' data-filepond-redirect-url="' . rex_escape($redirectUrl) . '"'
            . ' />'
            . '</div>';

        $fragment = new rex_fragment();
        $fragment->setVar('title', $addon->i18n('filepond_mediapool_replace_title'));
        $fragment->setVar('body', $body, false);
        $section = $fragment->parse('core/page/section.php');

        return (string) $ep->getSubject() . $section;
    });

    rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): void {
        if ('mediapool/media' !== rex_be_controller::getCurrentPage()) {
            return;
        }

        $subject = $ep->getSubject();
        if (!is_string($subject) || false === strpos($subject, 'name="file_new"')) {
            return;
        }

        $exchangeLabel = preg_quote(rex_i18n::msg('pool_file_exchange'), '#');
        $subject = (string) preg_replace(
            '#<dt>\s*<label[^>]*>\s*' . $exchangeLabel . '\s*</label>\s*</dt>\s*<dd>\s*<input[^>]*name="file_new"[^>]*>\s*</dd>#is',
            '',
            $subject,
            1
        );

        $ep->setSubject($subject);
    });
}

// Multiupload als Medienpool-Unterseite registrieren
$mediapoolSubpage = rex_config::get('filepond_uploader', 'mediapool_subpage', '');
if ($mediapoolSubpage === '|1|' || $mediapoolSubpage === '1') {
    rex_extension::register('PAGES_PREPARED', function (rex_extension_point $ep) {
        $user = rex::getUser();
        if (!$user) {
            return;
        }

        /** @var array<string, rex_be_page> $pages */
        $pages = $ep->getSubject();

        if (isset($pages['mediapool'])) {
            $mediapoolPage = $pages['mediapool'];

            $multiuploadPage = new rex_be_page('filepond_multiupload', rex_i18n::msg('filepond_multiupload_title'));
            $multiuploadPage->setIcon('rex-icon fa-solid fa-cloud-arrow-up');
            $multiuploadPage->setSubPath(rex_path::addon('filepond_uploader', 'pages/upload.php'));
            $multiuploadPage->setRequiredPermissions('filepond_uploader[upload]');

            // Nach der 'upload'-Seite einfügen
            $subpages = $mediapoolPage->getSubpages();
            $ordered = [];
            $inserted = false;
            foreach ($subpages as $key => $subpage) {
                $ordered[$key] = $subpage;
                if ($key === 'upload' && !$inserted) {
                    $ordered['filepond_multiupload'] = $multiuploadPage;
                    $inserted = true;
                }
            }
            if (!$inserted) {
                $ordered['filepond_multiupload'] = $multiuploadPage;
            }
            $mediapoolPage->setSubpages($ordered);
        }
    });
}

// Alt-Text-Checker als Medienpool-Unterseite registrieren
$enableAltChecker = rex_config::get('filepond_uploader', 'enable_alt_checker', '');
if ($enableAltChecker === '|1|' || $enableAltChecker === '1') {
    rex_extension::register('PAGES_PREPARED', function (rex_extension_point $ep) {
        $user = rex::getUser();
        if (!$user) return;
        
        // Nur für Admins oder Nutzer mit entsprechender Berechtigung
        if (!$user->isAdmin() && !$user->hasPerm('filepond_uploader[alt_checker]')) {
            return;
        }
        
        // Nur einbinden wenn med_alt Feld überhaupt vorhanden ist
        if (!AltTextChecker::checkAltFieldExists()) {
            return;
        }
        
        /** @var array<string, rex_be_page> $pages */
        $pages = $ep->getSubject();
        
        if (isset($pages['mediapool'])) {
            $mediapoolPage = $pages['mediapool'];
            
            // Neue Unterseite erstellen
            $altCheckerPage = new rex_be_page('alt_checker', rex_i18n::msg('filepond_alt_checker_title'));
            $altCheckerPage->setIcon('rex-icon fa-solid fa-universal-access');
            $altCheckerPage->setSubPath(rex_path::addon('filepond_uploader', 'pages/alt_checker.php'));
            $altCheckerPage->setRequiredPermissions('filepond_uploader[alt_checker]');
            
            // Als Unterseite hinzufügen
            $mediapoolPage->addSubpage($altCheckerPage);
        }
    });
}

// Info Center FilePond Upload Widget Integration
if (rex_addon::exists('info_center') && rex_addon::get('info_center')->isAvailable()) {
    rex_extension::register('PACKAGES_INCLUDED', function() {
        $infoCenter = \KLXM\InfoCenter\InfoCenter::getInstance();
        
        // Check if user has permission (only for logged-in users)
        if (rex::getUser()) {
            $widget = new UploadWidget();
            $widget->setPriority(0.5); // After TimeTracker (0), before Article (1)
            $infoCenter->registerWidget($widget);
        }
    });
}
