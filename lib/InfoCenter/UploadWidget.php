<?php

namespace FriendsOfRedaxo\FilePondUploader\InfoCenter;

use KLXM\InfoCenter\AbstractWidget;
use rex;
use rex_addon;
use rex_media_category_select;
use rex_media_perm;

use function sprintf;

// info_center ist optional: ohne das AddOn fehlt die Basisklasse, die Klasse wird dann nicht definiert.
if (!class_exists(AbstractWidget::class)) {
    return;
}

class UploadWidget extends AbstractWidget
{
    protected bool $supportsLazyLoading = false;

    public function __construct()
    {
        parent::__construct();
        $this->title = '📤 FilePond Upload';
        $this->priority = 100; // Higher priority = shown more prominently
    }

    public function render(): string
    {
        // Only show in backend
        if (!rex::isBackend()) {
            return '';
        }

        // Check if FilePond uploader addon exists and is available
        if (!rex_addon::exists('filepond_uploader')) {
            return $this->wrapContent('<p class="text-muted">FilePond Uploader Addon nicht installiert</p>');
        }

        $filepond = rex_addon::get('filepond_uploader');
        if (!$filepond->isAvailable()) {
            return $this->wrapContent('<p class="text-muted">FilePond Uploader Addon nicht verfügbar</p>');
        }

        // Generate unique ID for this widget instance
        $widgetId = 'filepond-widget-' . uniqid();

        // Get current MediaPool category (default to root)
        $currentCategory = rex_request('rex_file_category', 'int', 0);

        // Generate the category select like in the upload page
        $selMedia = new rex_media_category_select($checkPerm = true);
        $selMedia->setId($widgetId . '-category-select');
        $selMedia->setName('category_id');
        $selMedia->setSize(1);
        $selMedia->setSelected($currentCategory);
        $selMedia->setAttribute('class', 'form-select');
        $mediaPerm = rex::getUser() ? rex::getUser()->getComplexPerm('media') : null;
        if ($mediaPerm instanceof rex_media_perm && $mediaPerm->hasAll()) {
            $selMedia->addOption('Root-Kategorie', '0');
        }

        // Get current user language
        $currentUser = rex::getUser();
        $langCode = (null !== $currentUser) ? $currentUser->getLanguage() : 'de_de';

        // Get addon config values
        $addon = rex_addon::get('filepond_uploader');
        $maxFiles = $addon->getConfig('max_files', 30);
        $allowedTypes = $addon->getConfig('allowed_types', 'image/*');
        $maxFilesize = $addon->getConfig('max_filesize', 10);
        $skipMeta = (bool) $addon->getConfig('upload_skip_meta', false);
        $delayedUpload = (bool) $addon->getConfig('delayed_upload_mode', false);
        $titleRequired = (bool) $addon->getConfig('title_required_default', false);
        $clientResize = ('|1|' === $addon->getConfig('create_thumbnails', ''));
        $maxPixel = $addon->getConfig('max_pixel', 2100);
        $imageQuality = $addon->getConfig('image_quality', 90);

        $content = sprintf('
            <div class="filepond-upload-widget">
                <form action="index.php" method="post">
                    <input type="hidden" name="page" value="mediapool">
                    <input type="hidden" name="subpage" value="upload">
                    
                    <div class="form-group mb-3">
                        <label class="form-label">Kategorie:</label>
                        %s
                    </div>
                    
                    <div class="form-group">
                        <input type="hidden" 
                            id="%s"
                            data-widget="filepond"
                            data-filepond-cat="%d"
                            data-filepond-maxfiles="%d"
                            data-filepond-types="%s"
                            data-filepond-maxsize="%d"
                            data-filepond-lang="%s"
                            data-filepond-skip-meta="%s"
                            data-filepond-delayed-upload="%s"
                            data-filepond-title-required="%s"
                            data-filepond-client-resize="%s"
                            data-filepond-max-pixel="%d"
                            data-filepond-image-quality="%d"
                            value=""
                        >
                    </div>
                </form>
                
                <div class="mt-2">
                    <small class="text-muted">
                        <i class="rex-icon rex-icon-info"></i>
                        Dateien hierher ziehen oder klicken zum Auswählen
                    </small>
                </div>
            </div>
            
            <style>
                .filepond-upload-widget .form-select {
                    max-width: 100%%;
                }
            </style>
            
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                // Update category when select changes
                const categorySelect = document.getElementById("%s-category-select");
                const filepondInput = document.getElementById("%s");
                
                if (categorySelect && filepondInput) {
                    categorySelect.addEventListener("change", function() {
                        filepondInput.setAttribute("data-filepond-cat", this.value);
                    });
                }
            });
            </script>',
            $selMedia->get(),
            $widgetId,
            $currentCategory,
            $maxFiles,
            $allowedTypes,
            $maxFilesize,
            $langCode,
            $skipMeta ? 'true' : 'false',
            $delayedUpload ? 'true' : 'false',
            $titleRequired ? 'true' : 'false',
            $clientResize ? 'true' : 'false',
            $maxPixel,
            $imageQuality,
            $widgetId,
            $widgetId,
        );

        return $this->wrapContent($content);
    }
}
