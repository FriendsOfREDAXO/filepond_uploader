<?php

use FriendsOfRedaxo\FilePondUploader\Config;
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
        if (!Config::isEnabled('auto_cleanup_enabled') || [] === $this->storedFiles) {
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
                $errors[] = (null !== $emptyValue && '' !== $emptyValue) ? (string) $emptyValue : rex_i18n::msg('filepond_yform_empty_value_default');
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

        // Feldeinstellung vor globaler Einstellung
        $flag = function (string $element, bool $fallback): bool {
            $raw = (string) $this->getElement($element);

            return '' === $raw ? $fallback : '1' === $raw;
        };
        $number = function (string $element, int $fallback): int {
            $raw = (string) $this->getElement($element);

            return is_numeric($raw) ? (int) $raw : $fallback;
        };

        $category = (string) $this->getElement('category');

        $this->params['form_output'][$this->getId()] = $this->parse('value.filepond.tpl.php', [
            'category_id' => '' !== $category ? (int) $category : Config::int('category_id', 0),
            'value' => $this->getValue(),
            'files' => $files,
            'chunk_enabled' => $flag('chunk_enabled', Config::isEnabled('enable_chunks', true)),
            'chunk_size' => $number('chunk_size', Config::int('chunk_size', 5)) * 1024 * 1024,
            'skip_meta' => $flag('skip_meta', false),
            'delayed_upload' => $this->getElement('delayed_upload'),
            'alt_required' => $flag('alt_required', Config::isEnabled('alt_required_default', true)),
            'max_pixel' => $number('max_pixel', Config::int('client_max_pixel', Config::int('max_pixel', 2100))),
            'image_quality' => $number('image_quality', Config::int('client_image_quality', Config::int('image_quality', 90))),
            'client_resize' => $flag('client_resize', Config::isEnabled('create_thumbnails')),
            'ai_enabled' => $flag('ai_enabled', Config::isEnabled('enable_ai_alt') && Config::isEnabled('enable_ai_upload_modal', true)),
            'ai_target_field' => '' !== trim((string) $this->getElement('ai_target_field')) ? trim((string) $this->getElement('ai_target_field')) : Config::string('ai_target_field', 'med_alt'),
        ]);
    }

    public function getDescription(): string
    {
        return 'filepond|name|label|category|allowed_types|allowed_filesize|allowed_max_files|required|notice|empty_value|skip_meta[0,1]|chunk_enabled[0,1]|chunk_size[MB]|delayed_upload[0,1,2]|title_required[0,1]|alt_required[0,1]|max_pixel|image_quality|client_resize[0,1]|ai_enabled[0,1]|ai_target_field';
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefinitions(): array
    {
        $yesNo = ['0' => rex_i18n::msg('filepond_yform_no'), '1' => rex_i18n::msg('filepond_yform_yes')];

        return [
            'type' => 'value',
            'name' => 'filepond',
            'values' => [
                'name' => ['type' => 'name', 'label' => rex_i18n::msg('yform_values_defaults_name')],
                'label' => ['type' => 'text', 'label' => rex_i18n::msg('yform_values_defaults_label')],
                'category' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_category'),
                    'notice' => rex_i18n::msg('filepond_yform_category_notice'),
                    'default' => (string) Config::int('category_id', 0),
                ],
                'allowed_types' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_allowed_types'),
                    'notice' => rex_i18n::msg('filepond_yform_allowed_types_notice'),
                    'default' => Config::string('allowed_types', 'image/*'),
                ],
                'allowed_filesize' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_max_filesize'),
                    'default' => (string) Config::int('max_filesize', 10),
                ],
                'allowed_max_files' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_max_files'),
                    'default' => (string) Config::int('max_files', 10),
                ],
                'required' => ['type' => 'boolean', 'label' => rex_i18n::msg('filepond_yform_required'), 'default' => '0'],
                'notice' => ['type' => 'text', 'label' => rex_i18n::msg('yform_values_defaults_notice')],
                'empty_value' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_empty_value'),
                    'default' => rex_i18n::msg('filepond_yform_empty_value_default'),
                ],
                'skip_meta' => ['type' => 'checkbox', 'label' => rex_i18n::msg('filepond_yform_skip_meta'), 'default' => '0', 'options' => '0,1'],
                'chunk_enabled' => [
                    'type' => 'checkbox',
                    'label' => rex_i18n::msg('filepond_yform_chunk_enabled'),
                    'choices' => $yesNo,
                    'default' => Config::isEnabled('enable_chunks', true) ? '1' : '0',
                ],
                'chunk_size' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_chunk_size'),
                    'default' => (string) Config::int('chunk_size', 5),
                ],
                'delayed_upload' => [
                    'type' => 'choice',
                    'label' => rex_i18n::msg('filepond_yform_delayed_upload'),
                    'choices' => [
                        '0' => rex_i18n::msg('filepond_yform_delayed_upload_0'),
                        '1' => rex_i18n::msg('filepond_yform_delayed_upload_1'),
                        '2' => rex_i18n::msg('filepond_yform_delayed_upload_2'),
                    ],
                    'notice' => rex_i18n::msg('filepond_yform_delayed_upload_notice'),
                    'default' => '0',
                ],
                'title_required' => [
                    'type' => 'checkbox',
                    'label' => rex_i18n::msg('filepond_yform_title_required'),
                    'choices' => $yesNo,
                    'default' => '0',
                ],
                'alt_required' => [
                    'type' => 'checkbox',
                    'label' => rex_i18n::msg('filepond_yform_alt_required'),
                    'choices' => $yesNo,
                    'default' => Config::isEnabled('alt_required_default', true) ? '1' : '0',
                ],
                'max_pixel' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_max_pixel'),
                    'notice' => rex_i18n::msg('filepond_yform_max_pixel_notice'),
                    'default' => (string) Config::int('max_pixel', 2100),
                ],
                'image_quality' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_image_quality'),
                    'default' => (string) Config::int('image_quality', 90),
                ],
                'client_resize' => [
                    'type' => 'checkbox',
                    'label' => rex_i18n::msg('filepond_yform_client_resize'),
                    'choices' => $yesNo,
                    'default' => Config::isEnabled('create_thumbnails') ? '1' : '0',
                ],
                'ai_enabled' => [
                    'type' => 'checkbox',
                    'label' => rex_i18n::msg('filepond_yform_ai_enabled'),
                    'choices' => $yesNo,
                    'default' => Config::isEnabled('enable_ai_alt') && Config::isEnabled('enable_ai_upload_modal', true) ? '1' : '0',
                ],
                'ai_target_field' => [
                    'type' => 'text',
                    'label' => rex_i18n::msg('filepond_yform_ai_target_field'),
                    'notice' => rex_i18n::msg('filepond_yform_ai_target_field_notice'),
                    'default' => Config::string('ai_target_field', 'med_alt'),
                ],
            ],
            'description' => rex_i18n::msg('filepond_yform_description'),
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
            'notice' => rex_i18n::msg('filepond_yform_search_notice'),
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
        return 'FIND_IN_SET(' . $sql->escape($value) . ', ' . $sql->escapeIdentifier($field) . ')';
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
                return '<span style="color: #999;"><i class="fa fa-ban"></i> ' . rex_escape($filename) . ' (' . rex_i18n::msg('filepond_yform_not_found') . ')</span>';
            }

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
                    $displayText = ('' !== $title) ? $title : $ext . ' - ' . rex_i18n::msg('filepond_yform_file_one');
                    return '<span style="display: inline-flex; align-items: center;" title="' . rex_escape($filename) . '">' .
                           '<img src="' . rex_escape($imageUrl) . '" class="img-thumbnail" style="width: 40px; height: 40px; margin-right: 5px;" />' .
                           '<span>' . rex_escape($displayText) . '</span>' .
                           '</span>';
                }
            }

            // Für andere Dateitypen: Font Awesome Icon
            $extension = $media->getExtension();
            $icon = self::getFileIcon($extension);
            $extUpper = mb_strtoupper($extension);
            $title = $media->getTitle();
            $displayText = ('' !== $title) ? $title : $extUpper . ' - ' . rex_i18n::msg('filepond_yform_file_one');

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

        $title = rex_i18n::msg('filepond_yform_files_count', $fileCount) . ': ' . $extString;

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
               '<strong>' . rex_escape(rex_i18n::msg('filepond_yform_files_count', $fileCount)) . '</strong>' .
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
