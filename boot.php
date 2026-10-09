<?php

use FriendsOfRedaxo\FilePondUploader\Ai\AltTextGenerator;
use FriendsOfRedaxo\FilePondUploader\AltTextChecker;
use FriendsOfRedaxo\FilePondUploader\Api\AiGenerate;
use FriendsOfRedaxo\FilePondUploader\Api\AltChecker;
use FriendsOfRedaxo\FilePondUploader\Api\AutoMetainfo;
use FriendsOfRedaxo\FilePondUploader\Api\Upload;
use FriendsOfRedaxo\FilePondUploader\Api\YcomAuth;
use FriendsOfRedaxo\FilePondUploader\Config;
use FriendsOfRedaxo\FilePondUploader\Helper;
use FriendsOfRedaxo\FilePondUploader\MediaCleanup;
use FriendsOfRedaxo\FilePondUploader\MimeTypes;
use FriendsOfRedaxo\FilePondUploader\YcomAuthSettings;

/** @var rex_addon $this */

// Klassennamen bis 2.x: weiter nutzbar, aufgelöst erst bei Bedarf.
spl_autoload_register(static function (string $class): void {
    $class = ltrim($class, '\\');
    $alias = [
        'filepond_helper' => Helper::class,
        'filepond_alt_text_checker' => AltTextChecker::class,
        'filepond_ai_alt_generator' => AltTextGenerator::class,
        'rex_api_filepond_uploader' => Upload::class,
        'rex_api_filepond_ai_generate' => AiGenerate::class,
        'rex_api_filepond_alt_checker' => AltChecker::class,
        'rex_api_filepond_auto_metainfo' => AutoMetainfo::class,
        'rex_api_filepond_ycom_auth' => YcomAuth::class,
        'friendsofredaxo\\filepond\\filepondmediacleanup' => MediaCleanup::class,
        'friendsofredaxo\\filepond\\ycomauthsettings' => YcomAuthSettings::class,
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

if (rex::isBackend()) {
    rex_perm::register('filepond_uploader[mediaplace_upload]', rex_i18n::msg('filepond_perm_mediaplace_upload'));
    rex_perm::register(YcomAuthSettings::PERM, rex_i18n::msg('filepond_perm_ycom_media_auth'));
}

// MediaPlace-Upload-Anbieter (MP.registerUploadProvider()); ohne MediaPlace wirkungslos.
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

// Medienpool-MIME-Types um die in FilePond erlaubten Typen erweitern (nur wo hochgeladen wird)
if (rex_addon::get('mediapool')->isAvailable() && (rex::isBackend() || '' !== rex_request('rex-api-call', 'string', ''))) {
    MimeTypes::extendMediapool(Config::string('allowed_types'));
}

if (rex::isBackend() && rex::getUser()) {
    Helper::getStyles();
    Helper::getScripts();

    // KI-Button-Konfiguration fuer mediapool_ai.js direkt in die Seite (rex.filepond_ai),
    // statt sie auf jeder Backend-Seite per eigenem Request nachzuladen.
    rex_view::setJsProperty('filepond_ai', AutoMetainfo::getAiButtonConfig());
    rex_view::setJsProperty('filepond_csrf', Helper::csrfToken());

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

    // Verstecktes FilePond-Widget für den MediaPlace-Upload-Anbieter: nur mit MediaPlace und Recht
    $user = rex::getUser();
    if (rex_addon::get('mediaplace')->isAvailable() && ($user->isAdmin() || $user->hasPerm('filepond_uploader[mediaplace_upload]'))) {
    rex_view::addJsFile($this->getAssetsUrl('mediaplace_upload_provider.js'));
    rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): void {
        $subject = $ep->getSubject();
        if (!is_string($subject)) {
            return;
        }

        $currentUser = rex::getUser();
        $langCode = $currentUser ? $currentUser->getLanguage() : 'en_gb';
        $maxPixel = Config::int('client_max_pixel', 0) > 0 ? Config::int('client_max_pixel', 0) : Config::int('max_pixel', 2100);
        $quality = Config::int('client_image_quality', 0) > 0 ? Config::int('client_image_quality', 0) : Config::int('image_quality', 90);

        $inject = '<div id="filepond-mp3-upload-provider-wrap" style="display:none">'
            . '<input type="file" multiple'
            . ' id="filepond-mp3-upload-provider"'
            . ' data-widget="filepond"'
            . ' data-filepond-cat="0"'
            . ' data-filepond-types="' . rex_escape(Config::string('allowed_types', 'image/*,video/*,application/pdf')) . '"'
            . ' data-filepond-maxsize="' . Config::int('max_filesize', 200) . '"'
            . ' data-filepond-lang="' . rex_escape($langCode) . '"'
            . Helper::imageEditorAttribute()
            . ' data-filepond-skip-meta="false"'
            . ' data-filepond-delayed-upload="false"'
            . ' data-filepond-chunk-enabled="' . (Config::isEnabled('enable_chunks', true) ? 'true' : 'false') . '"'
            . ' data-filepond-chunk-size="' . (Config::int('chunk_size', 5) * 1024 * 1024) . '"'
            . ' data-filepond-title-required="' . (Config::isEnabled('title_required_default') ? 'true' : 'false') . '"'
            . ' data-filepond-alt-required="' . (Config::isEnabled('alt_required_default', true) ? 'true' : 'false') . '"'
            . ' data-filepond-max-pixel="' . $maxPixel . '"'
            . ' data-filepond-image-quality="' . $quality . '"'
            . ' data-filepond-client-resize="' . (Config::isEnabled('create_thumbnails', true) ? 'true' : 'false') . '"'
            . ' data-filepond-media-url="' . rex_escape(rex_url::media()) . '"'
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
}

if (Config::isEnabled('replace_mediapool')) {
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

if (rex::isBackend() && rex::getUser() && Config::isEnabled('enable_mediapool_replace', true)) {
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
            . ' data-filepond-maxsize="' . Config::int('max_filesize', 200) . '"'
            . ' data-filepond-lang="' . rex_escape((string) rex::getUser()?->getLanguage()) . '"'
            . ' data-filepond-skip-meta="true"'
            . ' data-filepond-delayed-upload="false"'
            . ' data-filepond-title-required="false"'
            . ' data-filepond-alt-required="false"'
            . ' data-filepond-chunk-enabled="' . (Config::isEnabled('enable_chunks', true) ? 'true' : 'false') . '"'
            . ' data-filepond-chunk-size="' . (Config::int('chunk_size', 5) * 1024 * 1024) . '"'
            . ' data-filepond-media-url="' . rex_escape(rex_url::media()) . '"'
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
if (rex::isBackend() && Config::isEnabled('mediapool_subpage')) {
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
if (rex::isBackend() && Config::isEnabled('enable_alt_checker', true)) {
    rex_extension::register('PAGES_PREPARED', function (rex_extension_point $ep) {
        $user = rex::getUser();
        // Unterseite nur im Medienpool nötig; spart den Spaltencheck auf allen anderen Seiten
        if (!$user || 'mediapool' !== rex_be_controller::getCurrentPagePart(1)) {
            return;
        }
        
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
