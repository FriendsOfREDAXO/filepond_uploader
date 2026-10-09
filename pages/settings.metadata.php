<?php

/** @var rex_addon $this */

$addon = rex_addon::get('filepond_uploader');
$form = rex_config_form::factory('filepond_uploader');

// ============================================================================
// 3. METADATEN & DIALOG-EINSTELLUNGEN
// ============================================================================
$form->addFieldset($addon->i18n('filepond_metadata_settings'));

$form->addRawField('<div class="row">');

// Linke Spalte
$form->addRawField('<div class="col-sm-6">');

// Meta-Dialog immer anzeigen
$field = $form->addCheckboxField('always_show_meta');
$field->setLabel($addon->i18n('filepond_settings_always_show_meta'));
$field->addOption($addon->i18n('filepond_settings_always_show_meta_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_always_show_meta_notice'));

// Meta-Dialoge bei Upload deaktivieren
$field = $form->addCheckboxField('upload_skip_meta');
$field->setLabel($addon->i18n('filepond_settings_upload_skip_meta'));
$field->addOption($addon->i18n('filepond_settings_upload_skip_meta_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_upload_skip_meta_notice'));

$form->addRawField('</div>');

// Rechte Spalte
$form->addRawField('<div class="col-sm-6">');

// Erforderliche Metadaten-Felder
$field = $form->addTextField('required_metadata_fields');
$field->setLabel($addon->i18n('filepond_settings_required_fields'));
$field->setNotice($addon->i18n('filepond_settings_required_fields_notice'));

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

// Ausgeschlossene Felder (Blacklist)
$form->addRawField('<div class="row"><div class="col-sm-12">');

$field = $form->addSelectField('excluded_metadata_fields');
$field->setLabel($addon->i18n('filepond_settings_excluded_fields'));
$field->setAttribute('multiple', 'multiple');
$field->setAttribute('class', 'form-control selectpicker');
$field->setNotice($addon->i18n('filepond_settings_excluded_fields_notice'));

$select = $field->getSelect();

// Standardfelder
$standardFields = ['title' => 'Titel (title)', 'med_alt' => 'Alt-Text (med_alt)', 'med_copyright' => 'Copyright (med_copyright)', 'med_description' => 'Beschreibung (med_description)'];

// Custom Metainfo Felder
if (rex_addon::exists('metainfo') && rex_addon::get('metainfo')->isAvailable()) {
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT name, title FROM ' . rex::getTable('metainfo_field') . ' WHERE name LIKE "med_%" ORDER BY priority');
    foreach ($sql as $row) {
        $name = (string) $row->getValue('name');
        if (!isset($standardFields[$name])) {
             $label = (string) $row->getValue('title');
             if (strpos($label, 'translate:') === 0) {
                 $label = rex_i18n::msg(substr($label, 10));
             }
             $standardFields[$name] = ($label !== '' ? $label : ucfirst($name)) . ' (' . $name . ')';
        }
    }
}

foreach ($standardFields as $key => $label) {
    $select->addOption($label, $key);
}

$form->addRawField('</div></div>');

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('filepond_settings_tab_metadata'));
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');
