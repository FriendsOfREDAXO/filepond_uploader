<?php

/** @var rex_addon $this */

$addon = rex_addon::get('filepond_uploader');
$form = rex_config_form::factory('filepond_uploader');

// ============================================================================
// 2. BILDVERARBEITUNG
// ============================================================================
$form->addFieldset($addon->i18n('filepond_image_processing'));

$form->addRawField('<div class="row">');

// Linke Spalte - Grundeinstellungen
$form->addRawField('<div class="col-sm-6">');

// Maximale Pixelgröße
$field = $form->addInputField('number', 'max_pixel', null, [
    'class' => 'form-control',
    'min' => '50',
    'required' => 'required'
]);
$field->setLabel($addon->i18n('filepond_settings_max_pixel'));
$field->setNotice($addon->i18n('filepond_settings_max_pixel_notice'));

// Bildqualität
$field = $form->addInputField('number', 'image_quality', null, [
    'class' => 'form-control',
    'min' => '10',
    'max' => '100',
    'required' => 'required'
]);
$field->setLabel($addon->i18n('filepond_settings_image_quality'));
$field->setNotice($addon->i18n('filepond_settings_image_quality_notice'));

// EXIF-Orientierung korrigieren
$field = $form->addCheckboxField('fix_exif_orientation');
$field->setLabel($addon->i18n('filepond_settings_fix_exif_orientation'));
$field->addOption($addon->i18n('filepond_settings_fix_exif_orientation_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_fix_exif_orientation_notice'));

$form->addRawField('</div>');

// Rechte Spalte - Verarbeitungsmethoden
$form->addRawField('<div class="col-sm-6">');

// Clientseitige Bildverkleinerung
$field = $form->addCheckboxField('create_thumbnails');
$field->setLabel($addon->i18n('filepond_settings_create_thumbnails'));
$field->addOption($addon->i18n('filepond_settings_create_thumbnails_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_create_thumbnails_notice'));

// Serverseitige Bildverarbeitung aktivieren
$field = $form->addCheckboxField('server_image_processing');
$field->setLabel($addon->i18n('filepond_settings_server_image_processing'));
$field->addOption($addon->i18n('filepond_settings_server_image_processing_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_server_image_processing_notice'));

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

// Erweiterte Einstellungen für kombinierte Verarbeitung (nur sichtbar wenn beide aktiv)
$clientMaxPixelVal = rex_config::get('filepond_uploader', 'client_max_pixel', '');
$clientMaxPixel = is_scalar($clientMaxPixelVal) ? (string) $clientMaxPixelVal : '';
$clientQualityVal = rex_config::get('filepond_uploader', 'client_image_quality', '');
$clientQuality = is_scalar($clientQualityVal) ? (string) $clientQualityVal : '';
$form->addRawField('
<div id="combined-processing-settings" class="panel panel-default" style="margin-top: 15px; display: none;">
    <div class="panel-heading"><strong>' . $addon->i18n('filepond_settings_combined_processing') . '</strong></div>
    <div class="panel-body">
        <p class="help-block">' . $addon->i18n('filepond_settings_combined_processing_notice') . '</p>
        <div class="row">
            <div class="col-sm-6">
                <div class="form-group">
                    <label class="control-label">' . $addon->i18n('filepond_settings_client_max_pixel') . '</label>
                    <input type="number" class="form-control" name="rex_config[filepond_uploader][client_max_pixel]" 
                           value="' . $clientMaxPixel . '" 
                           min="50" placeholder="' . $addon->i18n('filepond_settings_use_global') . '">
                    <p class="help-block small">' . $addon->i18n('filepond_settings_client_max_pixel_notice') . '</p>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-group">
                    <label class="control-label">' . $addon->i18n('filepond_settings_client_image_quality') . '</label>
                    <input type="number" class="form-control" name="rex_config[filepond_uploader][client_image_quality]" 
                           value="' . $clientQuality . '" 
                           min="10" max="100" placeholder="' . $addon->i18n('filepond_settings_use_global') . '">
                    <p class="help-block small">' . $addon->i18n('filepond_settings_client_image_quality_notice') . '</p>
                </div>
            </div>
        </div>
    </div>
</div>
');

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('filepond_settings_tab_images'));
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');
?>
<script nonce="<?= rex_response::getNonce() ?>">
(function () {
    function init() {
        var panel = document.getElementById('combined-processing-settings');
        var client = document.querySelector('input[name*="create_thumbnails"]');
        var server = document.querySelector('input[name*="server_image_processing"]');
        if (!panel || !client || !server) {
            return;
        }
        var toggle = function () {
            panel.style.display = client.checked && server.checked ? 'block' : 'none';
        };
        client.addEventListener('change', toggle);
        server.addEventListener('change', toggle);
        toggle();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
