<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex_addon;
use rex_view;

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
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-file-validate-type.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-file-validate-size.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-exif-orientation.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-preview.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-resize.js'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-transform.js'),
            $addon->getAssetsUrl('filepond/filepond.js'),
            $addon->getAssetsUrl('filepond_modal.js'),
            $addon->getAssetsUrl('filepond_widget.js'),
            $addon->getAssetsUrl('filepond_auto_metainfo.js'),  // Unser neues MetaInfo JavaScript
        ];

        if (rex::isBackend()) {
            $jsFiles[] = $addon->getAssetsUrl('mediapool_ai.js');
            $jsFiles[] = $addon->getAssetsUrl('mediaplace_upload_provider.js');
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
                '<script type="text/javascript" src="%s" defer></script>',
                $file,
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
            $addon->getAssetsUrl('filepond/filepond.css'),
            $addon->getAssetsUrl('filepond/plugins/filepond-plugin-image-preview.css'),
            $addon->getAssetsUrl('filepond_widget.css'),
            $addon->getAssetsUrl('filepond-custom-styles.css'), // Unsere neue CSS-Datei mit benutzerdefinierten Button-Stilen
            $addon->getAssetsUrl('filepond_metainfo_lang.css'),  // MetaInfo Lang Fields Styles
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
                '<link rel="stylesheet" type="text/css" href="%s">',
                $file,
            ),
            $cssFiles,
        ));
    }
}
