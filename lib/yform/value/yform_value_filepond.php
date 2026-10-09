<?php

use FriendsOfRedaxo\FilePondUploader\MediaCleanup;

class rex_yform_value_filepond extends rex_yform_value_abstract
{
    protected static function cleanValue(string $value): string
    {
        return implode(',', array_filter(array_map('trim', explode(',', str_replace('"', '', $value))), static function (string $v): bool {
            return '' !== $v;
        }));
    }

    /** @var list<string> Dateien des Datensatzes vor dem Speichern (für den Auto-Cleanup) */
    private array $storedFiles = [];

    /**
     * Ordnet einen Original-Dateinamen der neuesten Medienpool-Datei mit diesem Originalnamen zu.
     */
    protected static function getMediapoolFilename(string $originalFilename): string
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT filename FROM ' . rex::getTable('media') . ' WHERE originalname = ? ORDER BY id DESC LIMIT 1', [$originalFilename]);

        return $sql->getRows() > 0 ? (string) $sql->getValue('filename') : $originalFilename;
    }

    /**
     * Nur echte Medienpool-Dateinamen übernehmen: der Wert kommt vom Client und landet in
     * Mail-Anhängen und Pfaden.
     */
    protected static function sanitizeValue(string $value): string
    {
        $files = [];
        foreach (explode(',', self::cleanValue($value)) as $name) {
            if ('' === $name || basename($name) !== $name) {
                continue;
            }
            if (null === rex_media::get($name)) {
                $name = self::getMediapoolFilename($name);
            }
            if (null !== rex_media::get($name) && !in_array($name, $files, true)) {
                $files[] = $name;
            }
        }

        return implode(',', $files);
    }

    public function preValidateAction(): void
    {
        $this->storedFiles = [];
        if (!$this->params['send'] || (int) ($this->params['main_id'] ?? 0) <= 0) {
            return;
        }

        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT ' . $sql->escapeIdentifier($this->getName()) . ' FROM ' . $sql->escapeIdentifier((string) $this->params['main_table']) . ' WHERE id = ?',
            [(int) $this->params['main_id']],
        );
        if ($sql->getRows() > 0) {
            $this->storedFiles = array_values(array_filter(explode(',', self::cleanValue((string) $sql->getValue($this->getName())))));
        }
    }

    /**
     * Auto-Cleanup erst nach dem Speichern: entfernte Dateien löschen, wenn sie nirgends
     * mehr verwendet werden.
     */
    public function postAction(): void
    {
        if (0 === (int) rex_config::get('filepond_uploader', 'auto_cleanup_enabled', 0) || [] === $this->storedFiles) {
            return;
        }

        $current = array_filter(explode(',', self::cleanValue((string) $this->getValue())));
        $table = (string) ($this->params['main_table'] ?? '');
        $id = (int) ($this->params['main_id'] ?? 0);

        foreach (array_diff($this->storedFiles, $current) as $filename) {
            if (null === rex_media::get($filename) || [] !== MediaCleanup::findUsages($filename, $table, $id, $this->getName())) {
                continue;
            }

            // deleteMedia() prüft MEDIA_IS_IN_USE ohne Kontext, MediaCleanup liest ihn von hier
            $GLOBALS['filepond_cleanup_ignore'] = ['table' => $table, 'id' => $id, 'field' => $this->getName()];
            try {
                rex_media_service::deleteMedia($filename);
            } catch (Throwable $e) {
                rex_logger::logException($e);
            } finally {
                unset($GLOBALS['filepond_cleanup_ignore']);
            }
        }
    }

    public function enterObject(): void
    {
        $this->setValue($this->getValue());

        if ((bool) $this->params['send']) {
            $value = '';

            /** @var array<int, array<int, string>> $formData */
            $formData = rex_request::request('FORM', 'array', []);
            if (count($formData) > 0) {
                foreach ($formData as $form) {
                    if (isset($form[$this->getId()])) {
                        $value = $form[$this->getId()];
                        break;
                    }
                }
            } elseif (isset($this->params['real_field_names']) && $this->params['real_field_names']) {
                $requestValue = rex_request($this->getName(), 'string', '');
                if ('' !== $requestValue) {
                    $value = $requestValue;
                    $this->setValue($value);
                }
            }

            $errors = [];
            if (1 === (int) $this->getElement('required') && '' === $value) {
                $emptyValue = $this->getElement('empty_value');
                $errors[] = (null !== $emptyValue && '' !== $emptyValue) ? (string) $emptyValue : 'Bitte wählen Sie eine Datei aus.';
            }

            if (count($errors) > 0) {
                $this->params['warning'][$this->getId()] = $this->params['error_class'];
                $this->params['warning_messages'][$this->getId()] = implode(', ', $errors);
            }

            $value = self::sanitizeValue($value);

            $this->setValue($value);

            // Wert immer in die value_pools schreiben, auch wenn leer
            $this->params['value_pool']['email'][$this->getName()] = $value;
            if ($this->saveInDB()) {
                $this->params['value_pool']['sql'][$this->getName()] = $value;
            }
        }

        $files = [];
        $value = $this->getValue();

        if (null !== $value && '' !== $value) {
            $value = trim($value, '"');
            $fileNames = explode(',', $value);

            foreach ($fileNames as $fileName) {
                $fileName = trim($fileName);
                if ('' !== $fileName && is_file(rex_path::media($fileName))) {
                    $files[] = $fileName;
                }
            }
        }

        // Globale Einstellung für Meta-Dialog prüfen
        $alwaysShowMeta = (bool) rex_config::get('filepond_uploader', 'always_show_meta', false);
        $skipMeta = false;

        // Element-Einstellung hat höhere Priorität als globale Einstellung
        if (null !== $this->getElement('skip_meta') && false === $alwaysShowMeta) {
            $skipMeta = (bool) $this->getElement('skip_meta');
        }

        // Chunk-Upload-Einstellungen (Element-Einstellung > Config)
        $chunkEnabledElement = $this->getElement('chunk_enabled');
        if (null !== $chunkEnabledElement && '' !== (string) $chunkEnabledElement) {
            $enableChunks = '1' === (string) $chunkEnabledElement;
        } else {
            $enableChunks = (bool) rex_config::get('filepond_uploader', 'enable_chunks', true);
        }

        $chunkSizeElement = $this->getElement('chunk_size');
        if (null !== $chunkSizeElement && '' !== (string) $chunkSizeElement && is_numeric((string) $chunkSizeElement)) {
            $chunkSize = (int) $chunkSizeElement * 1024 * 1024;
        } else {
            $chunkSize = (int) rex_config::get('filepond_uploader', 'chunk_size', 5) * 1024 * 1024;
        }

        // Verzögerter Upload-Modus
        $delayedUpload = $this->getElement('delayed_upload');

        // Zusätzliche optionale Einstellungen (Element-Einstellung > Config)
        $altRequiredElement = $this->getElement('alt_required');
        if (null !== $altRequiredElement && '' !== (string) $altRequiredElement) {
            $altRequired = '1' === (string) $altRequiredElement;
        } else {
            $altRequiredRaw = rex_config::get('filepond_uploader', 'alt_required_default', '1');
            $altRequired = in_array($altRequiredRaw, [1, '1', true, 'true', '|1|'], true);
        }

        $maxPixelElement = $this->getElement('max_pixel');
        if (null !== $maxPixelElement && '' !== (string) $maxPixelElement && is_numeric((string) $maxPixelElement)) {
            $maxPixel = (int) $maxPixelElement;
        } else {
            $clientMaxPixel = rex_config::get('filepond_uploader', 'client_max_pixel', '');
            $cfgMaxPixel = rex_config::get('filepond_uploader', 'max_pixel', 2100);
            $maxPixel = (is_scalar($clientMaxPixel) && '' !== (string) $clientMaxPixel)
                ? (int) $clientMaxPixel
                : (int) $cfgMaxPixel;
        }

        $imageQualityElement = $this->getElement('image_quality');
        if (null !== $imageQualityElement && '' !== (string) $imageQualityElement && is_numeric((string) $imageQualityElement)) {
            $imageQuality = (int) $imageQualityElement;
        } else {
            $clientImageQuality = rex_config::get('filepond_uploader', 'client_image_quality', '');
            $cfgImageQuality = rex_config::get('filepond_uploader', 'image_quality', 90);
            $imageQuality = (is_scalar($clientImageQuality) && '' !== (string) $clientImageQuality)
                ? (int) $clientImageQuality
                : (int) $cfgImageQuality;
        }

        $clientResizeElement = $this->getElement('client_resize');
        if (null !== $clientResizeElement && '' !== (string) $clientResizeElement) {
            $clientResize = '1' === (string) $clientResizeElement;
        } else {
            $createThumbs = rex_config::get('filepond_uploader', 'create_thumbnails', '');
            $clientResize = '|1|' === (string) $createThumbs;
        }

        $aiEnabledElement = $this->getElement('ai_enabled');
        if (null !== $aiEnabledElement && '' !== (string) $aiEnabledElement) {
            $aiEnabled = '1' === (string) $aiEnabledElement;
        } else {
            $enableAiAltRaw = rex_config::get('filepond_uploader', 'enable_ai_alt', '0');
            $enableAiUploadModalRaw = rex_config::get('filepond_uploader', 'enable_ai_upload_modal', '1');
            $enableAiAlt = in_array($enableAiAltRaw, [1, '1', true, 'true', '|1|'], true);
            $enableAiUploadModal = in_array($enableAiUploadModalRaw, [1, '1', true, 'true', '|1|'], true);
            $aiEnabled = $enableAiAlt && $enableAiUploadModal;
        }

        $aiTargetFieldElement = $this->getElement('ai_target_field');
        if (null !== $aiTargetFieldElement && '' !== trim((string) $aiTargetFieldElement)) {
            $aiTargetField = trim((string) $aiTargetFieldElement);
        } else {
            $aiTargetFieldRaw = rex_config::get('filepond_uploader', 'ai_target_field', 'med_alt');
            $aiTargetField = is_string($aiTargetFieldRaw) && '' !== trim($aiTargetFieldRaw) ? trim($aiTargetFieldRaw) : 'med_alt';
        }

        $categoryElement = $this->getElement('category');
        $this->params['form_output'][$this->getId()] = $this->parse('value.filepond.tpl.php', [
            'category_id' => (null !== $categoryElement && '' !== $categoryElement) ? $categoryElement : rex_config::get('filepond_uploader', 'category_id', 0),
            'value' => $this->getValue(),
            'files' => $files,
            'chunk_enabled' => $enableChunks,
            'chunk_size' => $chunkSize,
            'skip_meta' => $skipMeta,
            'delayed_upload' => $delayedUpload,
            'alt_required' => $altRequired,
            'max_pixel' => $maxPixel,
            'image_quality' => $imageQuality,
            'client_resize' => $clientResize,
            'ai_enabled' => $aiEnabled,
            'ai_target_field' => $aiTargetField,
        ]);
    }

    public function getDescription(): string
    {
        return 'filepond|name|label|category|allowed_types[MIME-Types oder Dateiendungen]|allowed_filesize|allowed_max_files|required|notice|empty_value|skip_meta[0,1]|chunk_enabled[0,1]|chunk_size[MB]|delayed_upload[0,1,2]|title_required[0,1]|alt_required[0,1]|max_pixel|image_quality|client_resize[0,1]|ai_enabled[0,1]|ai_target_field
        
        Parameter-Details:
        - title_required[0,1]: Wenn auf 1 gesetzt, muss der Benutzer für jede hochgeladene Datei einen Titel angeben. Bei 0 ist der Titel optional.
        - delayed_upload[0,1,2]: 0=Sofortiger Upload, 1=Upload-Button, 2=Upload beim Formular-Submit';
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefinitions(): array
    {
        return [
            'type' => 'value',
            'name' => 'filepond',
            'values' => [
                'name' => ['type' => 'name',   'label' => rex_i18n::msg('yform_values_defaults_name')],
                'label' => ['type' => 'text',   'label' => rex_i18n::msg('yform_values_defaults_label')],
                'category' => [
                    'type' => 'text',
                    'label' => 'Medienkategorie ID',
                    'notice' => 'ID der Medienkategorie in die die Dateien geladen werden sollen',
                    'default' => (string) rex_config::get('filepond_uploader', 'category_id', 0),
                ],
                'allowed_types' => [
                    'type' => 'text',
                    'label' => 'Erlaubte Dateitypen',
                    'notice' => 'MIME-Types (z.B.: image/*,video/*,application/pdf) oder Dateiendungen (.pdf,.doc,.docx) - beide Formate können gemischt werden',
                    'default' => rex_config::get('filepond_uploader', 'allowed_types', 'image/*'),
                ],
                'allowed_filesize' => [
                    'type' => 'text',
                    'label' => 'Maximale Dateigröße (MB)',
                    'notice' => 'Größe in Megabyte',
                    'default' => (string) rex_config::get('filepond_uploader', 'max_filesize', 10),
                ],
                'allowed_max_files' => [
                    'type' => 'text',
                    'label' => 'Maximale Anzahl Dateien',
                    'default' => (string) rex_config::get('filepond_uploader', 'max_files', 10),
                ],
                'required' => ['type' => 'boolean', 'label' => 'Pflichtfeld', 'default' => '0'],
                'notice' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_notice')],
                'empty_value' => [
                    'type' => 'text',
                    'label' => 'Fehlermeldung wenn leer',
                    'default' => 'Bitte eine Datei auswählen.',
                ],
                'skip_meta' => ['type' => 'checkbox',  'label' => 'Metaabfrage deaktivieren', 'default' => '0', 'options' => '0,1'],
                'chunk_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Chunk-Upload aktivieren',
                    'choices' => ['0' => 'Nein', '1' => 'Ja'],
                    'default' => (bool) rex_config::get('filepond_uploader', 'enable_chunks', true) ? '1' : '0',
                ],
                'chunk_size' => [
                    'type' => 'text',
                    'label' => 'Chunk-Größe (MB)',
                    'notice' => 'Größe eines Upload-Chunks in Megabyte',
                    'default' => (string) rex_config::get('filepond_uploader', 'chunk_size', 5),
                ],
                'delayed_upload' => [
                    'type' => 'choice',
                    'label' => 'Verzögerter Upload-Modus',
                    'choices' => ['0' => 'Deaktiviert', '1' => 'Upload-Button', '2' => 'Submit-Button'],
                    'notice' => 'Dateien werden erst nach Klick auf den Upload-Button oder nach Formular Übermittlung hochgeladen',
                    'default' => '0',
                ],
                'title_required' => [
                    'type' => 'checkbox',
                    'label' => 'Titel-Feld als Pflichtfeld',
                    'choices' => ['0' => 'Nein', '1' => 'Ja'],
                    'notice' => 'Wenn aktiviert, wird das title Feld im Metadaten-Dialog als Pflichtfeld markiert',
                    'default' => '0',
                ],
                'alt_required' => [
                    'type' => 'checkbox',
                    'label' => 'ALT-Feld als Pflichtfeld',
                    'choices' => ['0' => 'Nein', '1' => 'Ja'],
                    'notice' => 'Wenn aktiviert, ist das med_alt Feld im Metadaten-Dialog ein Pflichtfeld',
                    'default' => in_array(rex_config::get('filepond_uploader', 'alt_required_default', '1'), [1, '1', true, 'true', '|1|'], true) ? '1' : '0',
                ],
                'max_pixel' => [
                    'type' => 'text',
                    'label' => 'Maximale Bildgröße (Pixel)',
                    'notice' => 'Wird für clientseitige Größenanpassung verwendet',
                    'default' => (string) rex_config::get('filepond_uploader', 'max_pixel', 2100),
                ],
                'image_quality' => [
                    'type' => 'text',
                    'label' => 'Bildqualität (10-100)',
                    'notice' => 'Qualität für JPEG/WebP bei clientseitiger Verarbeitung',
                    'default' => (string) rex_config::get('filepond_uploader', 'image_quality', 90),
                ],
                'client_resize' => [
                    'type' => 'checkbox',
                    'label' => 'Clientseitige Bildverkleinerung',
                    'choices' => ['0' => 'Nein', '1' => 'Ja'],
                    'default' => '|1|' === (string) rex_config::get('filepond_uploader', 'create_thumbnails', '') ? '1' : '0',
                ],
                'ai_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'AI-Vorschläge im Upload-Dialog',
                    'choices' => ['0' => 'Nein', '1' => 'Ja'],
                    'default' => (
                        in_array(rex_config::get('filepond_uploader', 'enable_ai_alt', '0'), [1, '1', true, 'true', '|1|'], true)
                        && in_array(rex_config::get('filepond_uploader', 'enable_ai_upload_modal', '1'), [1, '1', true, 'true', '|1|'], true)
                    ) ? '1' : '0',
                ],
                'ai_target_field' => [
                    'type' => 'text',
                    'label' => 'AI-Zielfeld',
                    'notice' => 'Feldname, in das AI-Vorschläge geschrieben werden (z.B. med_alt)',
                    'default' => (string) rex_config::get('filepond_uploader', 'ai_target_field', 'med_alt'),
                ],
            ],
            'description' => 'Filepond Dateiupload mit Medienpool-Integration und Chunk-Upload',
            'db_type' => ['text'],
            'multi_edit' => false,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function getSearchField(array $params): void
    {
        $params['searchForm']->setValueField('text', [
            'name' => $params['field']->getName(),
            'label' => $params['field']->getLabel(),
            'notice' => 'Dateiname eingeben',
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function getSearchFilter(array $params): string
    {
        $sql = rex_sql::factory();
        $value = (string) $params['value'];
        $field = $params['field']->getName();

        if ('(empty)' === $value) {
            return ' (' . $sql->escapeIdentifier($field) . ' = "" or ' . $sql->escapeIdentifier($field) . ' IS NULL) ';
        }
        if ('!(empty)' === $value) {
            return ' (' . $sql->escapeIdentifier($field) . ' <> "" and ' . $sql->escapeIdentifier($field) . ' IS NOT NULL) ';
        }

        $pos = strpos($value, '*');
        if (false !== $pos) {
            $value = str_replace('%', '\%', $value);
            $value = str_replace('*', '%', $value);
            return $sql->escapeIdentifier($field) . ' LIKE ' . $sql->escape($value);
        }
        return $sql->escapeIdentifier($field) . ' = ' . $sql->escape($value);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function getListValue(array $params): string
    {
        $files = array_filter(explode(',', self::cleanValue((string) $params['subject'])), static function (string $v): bool {
            return '' !== $v;
        });

        if (0 === count($files)) {
            return '-';
        }

        $fileCount = count($files);

        // Nur eine Datei: Zeige Icon/Thumbnail + Dateiname
        if (1 === $fileCount) {
            $filename = trim($files[0]);
            $media = rex_media::get($filename);

            if (null === $media) {
                return '<span style="color: #999;"><i class="fa fa-ban"></i> ' . rex_escape($filename) . ' (nicht gefunden)</span>';
            }

            $url = rex_url::backendPage('mediapool/detail', ['file_name' => $filename]);

            // Bei Bildern: Thumbnail (SVG direkt, andere über Media Manager)
            if ($media->isImage()) {
                $extension = mb_strtolower($media->getExtension());

                if ('svg' === $extension) {
                    // SVG direkt ausgeben (Vektorgrafik benötigt keinen Media Manager)
                    $imageUrl = $media->getUrl();
                } elseif (rex_addon::get('media_manager')->isAvailable()) {
                    // Andere Bilder über Media Manager
                    $imageUrl = rex_media_manager::getUrl('rex_media_small', $filename);
                } else {
                    $imageUrl = null;
                }

                if (null !== $imageUrl) {
                    $ext = mb_strtoupper($media->getExtension());
                    $title = $media->getTitle();
                    $displayText = ('' !== $title) ? $title : $ext . ' - 1 Datei';
                    return '<span style="display: inline-flex; align-items: center;" title="' . rex_escape($filename) . '">' .
                           '<img src="' . $imageUrl . '" class="img-thumbnail" style="width: 40px; height: 40px; margin-right: 5px;" />' .
                           '<span>' . rex_escape($displayText) . '</span>' .
                           '</span>';
                }
            }

            // Für andere Dateitypen: Font Awesome Icon
            $extension = $media->getExtension();
            $icon = self::getFileIcon($extension);
            $extUpper = mb_strtoupper($extension);
            $title = $media->getTitle();
            $displayText = ('' !== $title) ? $title : $extUpper . ' - 1 Datei';

            return '<span style="display: inline-flex; align-items: center;" title="' . rex_escape($filename) . '">' .
                   '<i class="fa ' . $icon . ' text-muted" style="font-size: 30px; width: 40px; text-align: center; margin-right: 5px;"></i>' .
                   '<span>' . rex_escape($displayText) . '</span>' .
                   '</span>';
        }

        // Mehrere Dateien: Kompakte Ansicht mit Badge
        $extensions = [];
        $imageCount = 0;
        $totalCount = 0;

        foreach ($files as $filename) {
            $filename = trim($filename);
            $media = rex_media::get($filename);

            if (null !== $media) {
                $ext = mb_strtolower($media->getExtension());
                $extensions[$ext] = ($extensions[$ext] ?? 0) + 1;
                ++$totalCount;

                if ($media->isImage()) {
                    ++$imageCount;
                }
            }
        }

        // Extension-Liste erstellen
        $extList = [];
        foreach ($extensions as $ext => $count) {
            $extList[] = $count > 1 ? $ext . ' (×' . $count . ')' : $ext;
        }
        $extString = implode(', ', $extList);

        $title = $fileCount . ' Dateien: ' . $extString;

        // Icon basierend auf Datei-Typen wählen
        if ($imageCount > 0) {
            // Bilder enthalten (einzeln oder gemischt)
            $iconClass = 'fa-solid fa-images';
        } else {
            // Nur Dokumente
            $iconClass = 'fa-solid fa-folder';
        }

        $multiIcon = '<i class="' . $iconClass . ' text-muted" style="font-size: 30px; width: 40px; text-align: center; margin-right: 5px;"></i>';

        return '<span style="display: inline-flex; align-items: center;" title="' . rex_escape($title) . '">' .
               $multiIcon .
               '<strong>' . $fileCount . '</strong>&nbsp;Dateien' .
               ' <small class="text-muted">(' . rex_escape($extString) . ')</small>' .
               '</span>';
    }

    /**
     * Gibt passendes Font Awesome Icon für Dateityp zurück.
     */
    private static function getFileIcon(string $extension): string
    {
        $extension = mb_strtolower($extension);

        $iconMap = [
            // Dokumente
            'pdf' => 'fa-file-pdf-o',
            'doc' => 'fa-file-word-o',
            'docx' => 'fa-file-word-o',
            'xls' => 'fa-file-excel-o',
            'xlsx' => 'fa-file-excel-o',
            'ppt' => 'fa-file-powerpoint-o',
            'pptx' => 'fa-file-powerpoint-o',
            'txt' => 'fa-file-text-o',
            'rtf' => 'fa-file-text-o',
            'csv' => 'fa-file-text-o',

            // Bilder
            'jpg' => 'fa-file-image-o',
            'jpeg' => 'fa-file-image-o',
            'png' => 'fa-file-image-o',
            'gif' => 'fa-file-image-o',
            'svg' => 'fa-file-image-o',
            'webp' => 'fa-file-image-o',

            // Video
            'mp4' => 'fa-file-video-o',
            'mov' => 'fa-file-video-o',
            'avi' => 'fa-file-video-o',
            'webm' => 'fa-file-video-o',

            // Audio
            'mp3' => 'fa-file-audio-o',
            'wav' => 'fa-file-audio-o',
            'ogg' => 'fa-file-audio-o',

            // Archive
            'zip' => 'fa-file-archive-o',
            'rar' => 'fa-file-archive-o',
            'tar' => 'fa-file-archive-o',
            'gz' => 'fa-file-archive-o',
        ];

        return $iconMap[$extension] ?? 'fa-file-o';
    }
}
