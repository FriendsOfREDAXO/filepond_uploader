<?php

/** @var rex_addon $this */

use FriendsOfRedaxo\AiPlatform\Service;
use FriendsOfRedaxo\FilePondUploader\Ai\AltTextGenerator;
use FriendsOfRedaxo\FilePondUploader\Api\AutoMetainfo;

$addon = rex_addon::get('filepond_uploader');
$form = rex_config_form::factory('filepond_uploader');

// ============================================================================
// 5. AI ALT-TEXT GENERIERUNG
// ============================================================================
$form->addFieldset($addon->i18n('filepond_ai_settings'));

// Status von ai_platform: ohne das AddOn bzw. ohne Profil "Bildverständnis" gibt es keine KI-Funktionen
$aiProfiles = [];
if (!rex_addon::get('ai_platform')->isAvailable()) {
    $form->addRawField(rex_view::warning($addon->i18n('filepond_settings_ai_platform_missing')));
} else {
    $aiProfiles = Service::getInstance()->getProfiles('image_understanding');
    $profilesUrl = rex_url::backendPage('ai_platform/profiles');
    if ([] === $aiProfiles) {
        $form->addRawField(rex_view::warning($addon->i18n('filepond_settings_ai_platform_no_profile', $profilesUrl)));
    } else {
        $check = (new AltTextGenerator())->testConnection();
        $form->addRawField($check['success'] ? rex_view::success(rex_escape($check['message'])) : rex_view::warning(rex_escape($check['message'])));
    }
}

// Status-Hinweis: die beiden Schalter unten ("AI-Button auf Medienpool-
// Detailseite" + "Zielfeld für AI-Vorschlag") gelten GLEICHERMASSEN fuer den
// klassischen Medienpool UND fuer MediaPlace, falls installiert -- es gibt
// keinen eigenen "MediaPlace aktivieren"-Schalter. MediaPlace erkennt sich
// selbst rein automatisch (siehe AutoMetainfo::
// getMediaplaceOwnAltField()): hat MediaPlace dort "Eigene Metadaten" an UND
// ein eigenes Feld vom Typ "ALT-Text" konfiguriert, wird DIESES Feld statt
// des klassischen "Zielfeld für AI-Vorschlag" benutzt -- ohne dass das hier
// irgendwo sichtbar waere. Dieser Block macht den tatsaechlich aktiven
// Zustand explizit, statt ihn nur in der Doku zu erklaeren.
if (class_exists(AutoMetainfo::class)) {
    $mediaplaceAvailable = rex_addon::exists('mediaplace') && rex_addon::get('mediaplace')->isAvailable();
    if (!$mediaplaceAvailable) {
        $statusText = $addon->i18n('filepond_settings_mediaplace_status_not_installed');
    } else {
        $ownAlt = AutoMetainfo::getMediaplaceOwnAltField();
        $statusText = $ownAlt['active']
            ? $addon->i18n('filepond_settings_mediaplace_status_active', rex_escape($ownAlt['key']))
            : $addon->i18n('filepond_settings_mediaplace_status_fallback');
    }
    $form->addRawField('<div class="alert alert-info" style="margin-bottom:15px;"><i class="rex-icon fa-info-circle"></i> ' . $statusText . '</div>');
}

$form->addRawField('<div class="row">');

// Linke Spalte - Aktivierung und Provider
$form->addRawField('<div class="col-sm-6">');

// AI Alt-Text aktivieren
$field = $form->addCheckboxField('enable_ai_alt');
$field->setLabel($addon->i18n('filepond_settings_enable_ai_alt'));
$field->addOption($addon->i18n('filepond_settings_enable_ai_alt_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_enable_ai_alt_notice'));

// AI-Button im Upload-Metadialog
$field = $form->addSelectField('enable_ai_upload_modal', null, [
    'class' => 'form-control selectpicker',
]);
$field->setLabel($addon->i18n('filepond_settings_enable_ai_upload_modal'));
$select = $field->getSelect();
$select->addOption($addon->i18n('filepond_settings_status_disabled'), '0');
$select->addOption($addon->i18n('filepond_settings_status_enabled'), '1');
$field->setNotice($addon->i18n('filepond_settings_enable_ai_upload_modal_notice'));

// AI-Button auf der Medienpool-Detailseite
$field = $form->addSelectField('enable_ai_mediapool_detail', null, [
    'class' => 'form-control selectpicker',
]);
$field->setLabel($addon->i18n('filepond_settings_enable_ai_mediapool_detail'));
$select = $field->getSelect();
$select->addOption($addon->i18n('filepond_settings_status_disabled'), '0');
$select->addOption($addon->i18n('filepond_settings_status_enabled'), '1');
$field->setNotice($addon->i18n('filepond_settings_enable_ai_mediapool_detail_notice'));

// ai_platform-Profil (Typ "Bildverständnis")
$field = $form->addSelectField('ai_platform_profile_id', null, [
    'class' => 'form-control selectpicker',
]);
$field->setLabel($addon->i18n('filepond_settings_ai_profile'));
$field->setNotice($addon->i18n('filepond_settings_ai_profile_notice'));
$select = $field->getSelect();
$select->addOption($addon->i18n('filepond_settings_ai_profile_default'), 0);
foreach ($aiProfiles as $profile) {
    $select->addOption((string) $profile['name'] . ' (' . (string) $profile['model'] . ')', (int) $profile['id']);
}

// Ziel-Feld für den AI-Zauberbutton im Upload-Modal
$field = $form->addTextField('ai_target_field', null, [
    'class' => 'form-control',
    'placeholder' => 'med_alt'
]);
$field->setLabel($addon->i18n('filepond_settings_ai_target_field'));
$field->setNotice($addon->i18n('filepond_settings_ai_target_field_notice'));

// Maximale Bildkante für AI (Resize vor Upload an den Provider)
$field = $form->addInputField('number', 'ai_max_image_dimension', null, [
    'class' => 'form-control',
    'min' => '256',
    'max' => '2048',
    'step' => '64',
    'placeholder' => '1024'
]);
$field->setLabel($addon->i18n('filepond_settings_ai_max_image_dimension'));
$field->setNotice($addon->i18n('filepond_settings_ai_max_image_dimension_notice'));

// Fallback-Sprache, wenn Modell eine Zielsprache nicht direkt liefern kann
$field = $form->addInputField('text', 'ai_fallback_language', null, [
    'class' => 'form-control',
    'maxlength' => '5',
    'placeholder' => 'en'
]);
$field->setLabel($addon->i18n('filepond_settings_ai_fallback_language'));
$field->setNotice($addon->i18n('filepond_settings_ai_fallback_language_notice'));

// Negativliste: Sprachen, die das aktuelle Modell nicht direkt erzeugen soll
$field = $form->addSelectField('ai_blocked_languages', null, [
    'class' => 'form-control selectpicker',
]);
$field->setAttribute('multiple', 'multiple');
$field->setLabel($addon->i18n('filepond_settings_ai_blocked_languages'));
$field->setNotice($addon->i18n('filepond_settings_ai_blocked_languages_notice'));

$selectedBlockedLanguages = [];
$blockedConfigRaw = rex_config::get('filepond_uploader', 'ai_blocked_languages', '');
if (is_array($blockedConfigRaw)) {
    $selectedBlockedLanguages = $blockedConfigRaw;
} elseif (is_string($blockedConfigRaw)) {
    if (str_contains($blockedConfigRaw, '|')) {
        $selectedBlockedLanguages = array_values(array_filter(explode('|', $blockedConfigRaw), static fn (string $v): bool => '' !== $v));
    } elseif ('' !== trim($blockedConfigRaw)) {
        $parts = preg_split('/[\s,;]+/', $blockedConfigRaw);
        if (is_array($parts)) {
            $selectedBlockedLanguages = $parts;
        }
    }
}

$selectedBlockedLanguages = array_values(array_unique(array_filter(array_map(static function ($value): string {
    if (!is_string($value)) {
        return '';
    }

    $trimmed = trim($value);
    if ('' === $trimmed) {
        return '';
    }

    return strtolower(substr($trimmed, 0, 2));
}, $selectedBlockedLanguages), static fn (string $v): bool => preg_match('/^[a-z]{2}$/', $v) === 1)));

$select = $field->getSelect();
$seenLanguageCodes = [];
foreach (rex_clang::getAll() as $clang) {
    $clangCode = strtolower((string) $clang->getCode());
    $shortCode = substr($clangCode, 0, 2);
    if (!preg_match('/^[a-z]{2}$/', $shortCode)) {
        continue;
    }

    if (in_array($shortCode, $seenLanguageCodes, true)) {
        continue;
    }

    $seenLanguageCodes[] = $shortCode;
    $label = $clang->getName() . ' (' . $shortCode . ')';
    $select->addOption($label, $shortCode);
}

if ([] !== $selectedBlockedLanguages) {
    $field->setValue($selectedBlockedLanguages);
}

$form->addRawField('</div>');

// Rechte Spalte - Custom Prompt
$form->addRawField('<div class="col-sm-6">');

// Prompt-Profil Auswahl
$field = $form->addSelectField('ai_prompt_profile', null, [
    'class' => 'form-control selectpicker',
]);
$field->setLabel($addon->i18n('filepond_settings_ai_prompt_profile'));
$select = $field->getSelect();
$select->addOption($addon->i18n('filepond_settings_ai_prompt_profile_accessibility'), 'accessibility');
$select->addOption($addon->i18n('filepond_settings_ai_prompt_profile_neutral'), 'neutral');
$select->addOption($addon->i18n('filepond_settings_ai_prompt_profile_seo'), 'seo');
$field->setNotice($addon->i18n('filepond_settings_ai_prompt_profile_notice'));

// AI Result-Cache aktivieren
$field = $form->addCheckboxField('ai_result_cache_enabled');
$field->setLabel($addon->i18n('filepond_settings_ai_result_cache_enabled'));
$field->addOption($addon->i18n('filepond_settings_ai_result_cache_enabled_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_ai_result_cache_enabled_notice'));

// AI Result-Cache TTL (Stunden)
$field = $form->addInputField('number', 'ai_result_cache_ttl_hours', null, [
    'class' => 'form-control',
    'min' => '1',
    'max' => '8760',
    'placeholder' => '168',
]);
$field->setLabel($addon->i18n('filepond_settings_ai_result_cache_ttl_hours'));
$field->setNotice($addon->i18n('filepond_settings_ai_result_cache_ttl_hours_notice'));

// Custom AI Prompt
$field = $form->addTextAreaField('ai_alt_prompt', null, [
    'class' => 'form-control',
    'rows' => '4',
    'style' => 'font-family: monospace; font-size: 12px;'
]);
$field->setLabel($addon->i18n('filepond_settings_ai_prompt'));
$field->setNotice($addon->i18n('filepond_settings_ai_prompt_notice'));

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('filepond_settings_tab_ai'));
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');
