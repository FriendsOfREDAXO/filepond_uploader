<?php

/** @var rex_addon $this */

$addon = rex_addon::get('filepond_uploader');
$form = rex_config_form::factory('filepond_uploader');

// ============================================================================
// 4. MEDIENPOOL-INTEGRATION
// ============================================================================
$form->addFieldset($addon->i18n('filepond_mediapool_settings'));

$form->addRawField('<div class="row">');

// Linke Spalte
$form->addRawField('<div class="col-sm-6">');

// Auto-Cleanup für ungenutzte Medien
$field = $form->addSelectField('auto_cleanup_enabled');
$field->setLabel($addon->i18n('filepond_auto_cleanup'));
$select = $field->getSelect();
$select->addOption($addon->i18n('filepond_auto_cleanup_disabled'), '0');
$select->addOption($addon->i18n('filepond_auto_cleanup_enabled_label'), '1');
$field->setNotice($addon->i18n('filepond_auto_cleanup_notice'));

// Medienpool ersetzen
$field = $form->addCheckboxField('replace_mediapool');
$field->setLabel($addon->i18n('filepond_settings_replace_mediapool'));
$field->addOption($addon->i18n('filepond_settings_replace_mediapool'), 1);
$field->setNotice($addon->i18n('filepond_settings_replace_mediapool_notice'));

$field = $form->addCheckboxField('enable_mediapool_replace');
$field->setLabel($addon->i18n('filepond_settings_enable_mediapool_replace'));
$field->addOption($addon->i18n('filepond_settings_enable_mediapool_replace'), 1);
$field->setNotice($addon->i18n('filepond_settings_enable_mediapool_replace_notice'));

// Multiupload als Medienpool-Unterseite
$field = $form->addCheckboxField('mediapool_subpage');
$field->setLabel($addon->i18n('filepond_settings_mediapool_subpage'));
$field->addOption($addon->i18n('filepond_settings_mediapool_subpage_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_mediapool_subpage_notice'));

$form->addRawField('</div>');

// Rechte Spalte
$form->addRawField('<div class="col-sm-6">');

// Alt-Text-Checker aktivieren
$field = $form->addCheckboxField('enable_alt_checker');
$field->setLabel($addon->i18n('filepond_settings_alt_checker'));
$field->addOption($addon->i18n('filepond_settings_alt_checker_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_alt_checker_notice'));

// Statistik anzeigen
$field = $form->addCheckboxField('show_alt_stats');
$field->setLabel($addon->i18n('filepond_settings_show_alt_stats'));
$field->addOption($addon->i18n('filepond_settings_show_alt_stats_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_show_alt_stats_notice'));

// YCom Media Auth Defaults beim Upload setzen (nur wenn ycom/media_auth Plugin verfügbar)
if (rex_addon::get('ycom')->isAvailable()
    && rex_plugin::get('ycom', 'media_auth')->isAvailable()) {
    $field = $form->addCheckboxField('ycom_media_auth_defaults_enabled');
    $field->setLabel($addon->i18n('filepond_ycom_media_auth_defaults_enabled'));
    $field->addOption($addon->i18n('filepond_ycom_media_auth_defaults_enabled_label'), 1);
    $field->setNotice($addon->i18n('filepond_ycom_media_auth_defaults_enabled_notice'));
}

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

// ============================================================================
// 5. ANZEIGE-EINSTELLUNGEN
// ============================================================================
$form->addFieldset($addon->i18n('filepond_settings_alt_checker_list'));

$form->addRawField('<div class="row">');
$form->addRawField('<div class="col-sm-6">');

// Elemente pro Seite
$field = $form->addInputField('number', 'items_per_page', null, [
    'class' => 'form-control',
    'min' => '10',
    'max' => '500'
]);
$field->setLabel($addon->i18n('filepond_settings_items_per_page'));
$field->setNotice($addon->i18n('filepond_settings_items_per_page_notice'));

$form->addRawField('</div>');
$form->addRawField('<div class="col-sm-6">');

// Sortierung Alt-Text Checker
$field = $form->addSelectField('alt_checker_sort');
$field->setLabel($addon->i18n('filepond_settings_alt_checker_sort'));
$select = $field->getSelect();
$select->addOption($addon->i18n('filepond_sort_createdate_desc'), 'createdate_desc');
$select->addOption($addon->i18n('filepond_sort_createdate_asc'), 'createdate_asc');
$select->addOption($addon->i18n('filepond_sort_filename_asc'), 'filename_asc');
$select->addOption($addon->i18n('filepond_sort_filename_desc'), 'filename_desc');

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('filepond_settings_tab_mediapool'));
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');
