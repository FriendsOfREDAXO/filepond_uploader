<?php

use FriendsOfRedaxo\FilePondUploader\Helper;
/** @var rex_yform_value_filepond $this */

$class       = $this->getElement('required') ? 'form-is-required ' : '';
$class_group = trim('form-group ' . $class . $this->getWarningClass());

// Value bereinigen
$value = str_replace(['"', ' '], '', $this->getValue() ?: '');
$fileNames = array_filter(explode(',', $value));

// Existierende Dateien für FilePond vorbereiten
$existingFiles = [];
foreach ($fileNames as $fileName) {
    if (file_exists(rex_path::media($fileName))) {
        $media = rex_media::get($fileName);
        if ($media) {
            $existingFiles[] = [
                'source' => $fileName,
                'options' => [
                    'type' => 'local',
                    'metadata' => [
                        'title' => $media->getValue('title'),
                        'alt' => $media->getValue('med_alt'),
                        'copyright' => $media->getValue('med_copyright')
                        // med_description Referenz wurde hier entfernt
                    ]
                ]
            ];
        }
    }
}

$currentUser = rex::getUser();
$langCodeVal = $currentUser ? $currentUser->getLanguage() : rex_config::get('filepond_uploader', 'lang', 'en_gb');
$langCode = is_string($langCodeVal) ? $langCodeVal : 'en_gb';

// Von YForm-parse() via extract() gesetzte Variablen sicher deklarieren
$skip_meta = isset($skip_meta) ? (bool) $skip_meta : false;
$chunk_enabled = isset($chunk_enabled) ? (bool) $chunk_enabled : false;
$chunk_size = isset($chunk_size) && is_numeric($chunk_size) ? (int) $chunk_size : 0;
$delayed_upload = isset($delayed_upload) && is_numeric($delayed_upload) ? (int) $delayed_upload : 0;
$alt_required = isset($alt_required) ? (bool) $alt_required : null;
$max_pixel = isset($max_pixel) && is_numeric($max_pixel) ? (int) $max_pixel : null;
$image_quality = isset($image_quality) && is_numeric($image_quality) ? (int) $image_quality : null;
$client_resize = isset($client_resize) ? (bool) $client_resize : null;
$ai_enabled = isset($ai_enabled) ? (bool) $ai_enabled : null;
$ai_target_field = isset($ai_target_field) && is_string($ai_target_field) ? trim($ai_target_field) : '';

// Config-Werte typsicher extrahieren
$cfgCatId = $this->getElement('category');
$dataCatId = ($cfgCatId === '0' || $cfgCatId) ? (string) $cfgCatId : '';
if ($dataCatId === '') {
    $cfgCatIdVal = rex_config::get('filepond_uploader', 'category_id', 0);
    $dataCatId = is_numeric($cfgCatIdVal) ? (string) (int) $cfgCatIdVal : '0';
}
$cfgMaxFiles = $this->getElement('allowed_max_files');
$dataMaxFiles = $cfgMaxFiles ? (string) $cfgMaxFiles : '';
if ($dataMaxFiles === '') {
    $cfgMaxFilesVal = rex_config::get('filepond_uploader', 'max_files', 30);
    $dataMaxFiles = is_numeric($cfgMaxFilesVal) ? (string) (int) $cfgMaxFilesVal : '30';
}
$cfgTypes = $this->getElement('allowed_types');
$dataTypes = is_string($cfgTypes) && $cfgTypes !== '' ? $cfgTypes : '';
if ($dataTypes === '') {
    $cfgTypesVal = rex_config::get('filepond_uploader', 'allowed_types', 'image/*');
    $dataTypes = is_string($cfgTypesVal) ? $cfgTypesVal : 'image/*';
}
$cfgMaxSize = $this->getElement('allowed_filesize');
$dataMaxSize = $cfgMaxSize ? (string) $cfgMaxSize : '';
if ($dataMaxSize === '') {
    $cfgMaxSizeVal = rex_config::get('filepond_uploader', 'max_filesize', 10);
    $dataMaxSize = is_numeric($cfgMaxSizeVal) ? (string) (int) $cfgMaxSizeVal : '10';
}
$cfgClientMaxPixel = rex_config::get('filepond_uploader', 'client_max_pixel', '');
$cfgMaxPixel = rex_config::get('filepond_uploader', 'max_pixel', 2100);
$dataMaxPixel = null !== $max_pixel
    ? (string) $max_pixel
    : (is_scalar($cfgClientMaxPixel) && $cfgClientMaxPixel !== '' ? (string) $cfgClientMaxPixel : (is_numeric($cfgMaxPixel) ? (string) (int) $cfgMaxPixel : '2100'));
$cfgClientQuality = rex_config::get('filepond_uploader', 'client_image_quality', '');
$cfgQuality = rex_config::get('filepond_uploader', 'image_quality', 90);
$dataQuality = null !== $image_quality
    ? (string) $image_quality
    : (is_scalar($cfgClientQuality) && $cfgClientQuality !== '' ? (string) $cfgClientQuality : (is_numeric($cfgQuality) ? (string) (int) $cfgQuality : '90'));
$cfgCreateThumbs = rex_config::get('filepond_uploader', 'create_thumbnails', '');
$dataClientResize = null !== $client_resize
    ? ($client_resize ? 'true' : 'false')
    : ((is_string($cfgCreateThumbs) && $cfgCreateThumbs === '|1|') ? 'true' : 'false');
$dataTitleRequired = $this->getElement('title_required') ? 'true' : 'false';
$isEnabledConfig = static function (string $key, bool $default): bool {
    $raw = rex_config::get('filepond_uploader', $key, $default ? '1' : '0');

    return in_array($raw, [1, '1', true, 'true', '|1|'], true);
};

$dataAltRequired = null !== $alt_required
    ? ($alt_required ? 'true' : 'false')
    : ($isEnabledConfig('alt_required_default', true) ? 'true' : 'false');

$cfgAiEnabled = $isEnabledConfig('enable_ai_alt', false)
    && $isEnabledConfig('enable_ai_upload_modal', true);
$dataAiEnabled = null !== $ai_enabled ? ($ai_enabled ? 'true' : 'false') : ($cfgAiEnabled ? 'true' : 'false');
$cfgAiTargetFieldVal = rex_config::get('filepond_uploader', 'ai_target_field', 'med_alt');
$dataAiTargetField = '' !== $ai_target_field
    ? $ai_target_field
    : (is_string($cfgAiTargetFieldVal) && '' !== trim($cfgAiTargetFieldVal) ? trim($cfgAiTargetFieldVal) : 'med_alt');

if (class_exists(Helper::class)) {
    echo Helper::getStyles();
    echo Helper::getScripts();
}
?>
<div class="<?= $class_group ?>" id="<?= $this->getHTMLId() ?>">
    <label class="control-label" for="<?= $this->getFieldId() ?>"><?= $this->getLabel() ?></label>
    
    <input type="hidden" 
       name="<?= $this->getFieldName() ?>" 
       value="<?= rex_escape((string) $value) ?>"
       data-widget="filepond"
       data-filepond-cat="<?= rex_escape((string) $dataCatId) ?>"
      <?= Helper::widgetSecurityAttributes((int) $dataCatId, (string) $dataTypes, (int) $dataMaxSize) ?>
       data-filepond-maxfiles="<?= rex_escape((string) $dataMaxFiles) ?>"
       data-filepond-types="<?= rex_escape((string) $dataTypes) ?>"
       data-filepond-maxsize="<?= rex_escape((string) $dataMaxSize) ?>"
       data-filepond-lang="<?= rex_escape((string) $langCode) ?>"
       data-filepond-skip-meta="<?= $skip_meta ? 'true' : 'false' ?>"
       data-filepond-chunk-enabled="<?= $chunk_enabled ? 'true' : 'false' ?>"
       data-filepond-chunk-size="<?= rex_escape((string) $chunk_size) ?>"
       data-filepond-delayed-upload="<?= (1 === $delayed_upload || 2 === $delayed_upload) ? 'true' : 'false' ?>"
       data-filepond-delayed-type="<?= rex_escape((string) $delayed_upload) ?>"
       data-filepond-title-required="<?= rex_escape((string) $dataTitleRequired) ?>" 
       data-filepond-alt-required="<?= rex_escape((string) $dataAltRequired) ?>"
       data-filepond-max-pixel="<?= rex_escape((string) $dataMaxPixel) ?>" 
       data-filepond-image-quality="<?= rex_escape((string) $dataQuality) ?>" 
       data-filepond-client-resize="<?= rex_escape((string) $dataClientResize) ?>"
         data-filepond-ai-enabled="<?= rex_escape((string) $dataAiEnabled) ?>"
         data-filepond-ai-target-field="<?= rex_escape($dataAiTargetField) ?>"
    />
    
    <?php if ($notice = $this->getElement('notice')): ?>
        <p class="help-block small"><?= rex_i18n::translate($notice, false) ?></p>
    <?php endif ?>

    <?php if (isset($this->params['warning_messages'][$this->getId()]) && !$this->params['hide_field_warning_messages']): ?>
        <p class="help-block text-warning small"><?= rex_i18n::translate($this->params['warning_messages'][$this->getId()], false) ?></p>
    <?php endif ?>
</div>
