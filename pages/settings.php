<?php

use FriendsOfRedaxo\FilePondUploader\Ai\AltTextGenerator;
use FriendsOfRedaxo\FilePondUploader\Api\AutoMetainfo;
use FriendsOfRedaxo\FilePondUploader\Api\Upload;
$addon = rex_addon::get('filepond_uploader');

// allowed_types manuell speichern (wird per addRawField/Accordion statt addTextAreaField gerendert)
if ('post' === strtolower(rex_server('REQUEST_METHOD', 'string', ''))) {
    $postedTypes = rex_post('allowed_types', 'string', null);
    if (null !== $postedTypes) {
        rex_config::set('filepond_uploader', 'allowed_types', $postedTypes);
    }
}

// Formular erstellen
$form = rex_config_form::factory('filepond_uploader');

// ============================================================================
// 0. ALLGEMEIN (WICHTIGSTE EINSTELLUNGEN)
// ============================================================================
$form->addFieldset('Allgemein (Wichtigste Einstellungen)');

$form->addRawField('<div class="row">');

// Linke Spalte
$form->addRawField('<div class="col-sm-6">');

// Maximale Anzahl Dateien
$field = $form->addInputField('number', 'max_files', null, [
    'class' => 'form-control',
    'min' => '1',
    'required' => 'required'
]);
$field->setLabel($addon->i18n('filepond_settings_max_files'));

// Maximale Dateigröße
$field = $form->addInputField('number', 'max_filesize', null, [
    'class' => 'form-control',
    'min' => '1',
    'required' => 'required'
]);
$field->setLabel($addon->i18n('filepond_settings_maxsize'));
$field->setNotice($addon->i18n('filepond_settings_maxsize_notice'));

// Verzögerter Upload-Modus
$field = $form->addCheckboxField('delayed_upload_mode');
$field->setLabel($addon->i18n('filepond_settings_delayed_upload'));
$field->addOption($addon->i18n('filepond_settings_delayed_upload_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_delayed_upload_notice'));

// Fallback Medienkategorie
$field = $form->addSelectField('category_id', null, [
    'class' => 'form-control selectpicker'
]);
$field->setLabel($addon->i18n('filepond_settings_fallback_category'));
$field->setNotice($addon->i18n('filepond_settings_fallback_category_notice'));

$select = $field->getSelect();
$select->addOption($addon->i18n('filepond_upload_no_category'), 0);

// Alle Medienkategorien laden und zum Select hinzufügen
$mediaCategories = rex_media_category::getRootCategories();
if (!empty($mediaCategories)) {
    $addCategories = function($categories, $level = 0) use (&$addCategories, $select) {
        foreach ($categories as $category) {
            if ($level > 0) {
                $prefix = str_repeat('· ', $level - 1) . '└─ ';
            } else {
                $prefix = '';
            }
            $select->addOption($prefix . $category->getName(), $category->getId());
            if ($children = $category->getChildren()) {
                $addCategories($children, $level + 1);
            }
        }
    };
    $addCategories($mediaCategories);
}

$form->addRawField('</div>');

// Rechte Spalte
$form->addRawField('<div class="col-sm-6">');

// Sprache
$field = $form->addSelectField('lang', null, [
    'class' => 'form-control selectpicker'
]);
$field->setLabel($addon->i18n('filepond_settings_lang'));
$select = $field->getSelect();
$select->addOption('Deutsch', 'de_de');
$select->addOption('English', 'en_gb');
$field->setNotice($addon->i18n('filepond_settings_lang_notice'));

// Titel-Feld als Pflichtfeld
$field = $form->addCheckboxField('title_required_default');
$field->setLabel($addon->i18n('filepond_settings_title_required'));
$field->addOption($addon->i18n('filepond_settings_title_required_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_title_required_notice'));

// ALT-Feld als Pflichtfeld
$field = $form->addSelectField('alt_required_default');
$field->setLabel($addon->i18n('filepond_settings_alt_required'));
$select = $field->getSelect();
$select->addOption($addon->i18n('filepond_settings_status_disabled'), '0');
$select->addOption($addon->i18n('filepond_settings_status_enabled'), '1');
$field->setNotice($addon->i18n('filepond_settings_alt_required_notice'));

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

// ============================================================================
// 1. UPLOAD-EINSTELLUNGEN
// ============================================================================
$form->addFieldset($addon->i18n('filepond_upload_settings'));

$form->addRawField('<div class="row">');

// Linke Spalte – Upload-Einstellungen
$form->addRawField('<div class="col-sm-6">');

// Chunk-Upload aktivieren/deaktivieren
$field = $form->addCheckboxField('enable_chunks');
$field->setLabel($addon->i18n('filepond_settings_enable_chunks'));
$field->addOption($addon->i18n('filepond_settings_enable_chunks_label'), 1);
$field->setNotice($addon->i18n('filepond_settings_enable_chunks_notice'));

// Chunk-Größe – PHP-Limits ermitteln
$parseIniSize = static function (string $size): int {
    $size = trim($size);
    $unit = strtolower(substr($size, -1));
    $value = (int) $size;
    return match ($unit) {
        'g' => $value * 1024 * 1024 * 1024,
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
};
$uploadMaxFilesize = ini_get('upload_max_filesize') ?: '2M';
$postMaxSize = ini_get('post_max_size') ?: '8M';
$phpMaxUploadMb = (int) (min($parseIniSize($uploadMaxFilesize), $parseIniSize($postMaxSize)) / (1024 * 1024));
$field = $form->addInputField('number', 'chunk_size', null, [
    'class' => 'form-control',
    'min' => '1',
    'max' => (string) $phpMaxUploadMb,
    'required' => 'required'
]);
$field->setLabel($addon->i18n('filepond_settings_chunk_size'));
$field->setNotice($addon->i18n('filepond_settings_chunk_size_notice', $phpMaxUploadMb, $uploadMaxFilesize, $postMaxSize));

$form->addRawField('</div>');

// Rechte Spalte – Dateitypen
$form->addRawField('<div class="col-sm-6">');

// Erlaubte Dateitypen – Accordion mit Checkboxen
$currentTypesValue = rex_config::get('filepond_uploader', 'allowed_types', 'image/*,video/*,application/pdf');
$currentTypes = array_map('trim', explode(',', $currentTypesValue));

$typeGroups = [
    'Bilder' => [
        'image/*' => 'Alle Bilder (image/*)',
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/gif' => 'GIF',
        'image/webp' => 'WebP',
        'image/svg+xml' => 'SVG',
        'image/tiff' => 'TIFF',
        'image/bmp' => 'BMP',
        'image/heic' => 'HEIC',
        'image/avif' => 'AVIF',
        'image/x-icon' => 'ICO',
    ],
    'Dokumente' => [
        'application/pdf' => 'PDF',
        'text/plain' => 'Text (.txt)',
        'text/csv' => 'CSV',
        'text/calendar' => 'iCalendar (.ics)',
        'text/x-vcalendar' => 'vCalendar (.vcal)',
        'text/vcard' => 'vCard (.vcf)',
        'text/markdown' => 'Markdown (.md)',
        'application/rtf' => 'RTF',
        'application/json' => 'JSON',
        'text/xml' => 'XML',
        'text/vtt' => 'WebVTT (.vtt)',
        'text/srt' => 'Untertitel (.srt)',
        'application/epub+zip' => 'E-Book (.epub)',
        'application/postscript' => 'PostScript (.eps)',
    ],
    'Archive' => [
        'application/zip' => 'ZIP',
        'application/x-gzip' => 'GZIP (.gz)',
        'application/x-tar' => 'TAR',
        'application/x-rar-compressed' => 'RAR',
        'application/x-7z-compressed' => '7-Zip (.7z)',
    ],
    'Video' => [
        'video/*' => 'Alle Videos (video/*)',
        'video/mp4' => 'MP4',
        'video/mpeg' => 'MPEG',
        'video/quicktime' => 'QuickTime (.mov)',
        'video/webm' => 'WebM',
        'video/ogg' => 'OGG Video',
        'video/x-msvideo' => 'AVI',
        'video/x-matroska' => 'MKV',
    ],
    'Audio' => [
        'audio/*' => 'Alle Audio (audio/*)',
        'audio/mpeg' => 'MP3',
        'audio/wav' => 'WAV',
        'audio/ogg' => 'OGG Audio',
        'audio/aac' => 'AAC',
        'audio/midi' => 'MIDI',
        'audio/flac' => 'FLAC',
        'audio/mp4' => 'M4A',
        'audio/webm' => 'WebM Audio',
    ],
    'Office' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word (.docx)',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel (.xlsx)',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint (.pptx)',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template' => 'Word-Vorlage (.dotx)',
        'application/vnd.openxmlformats-officedocument.presentationml.template' => 'PowerPoint-Vorlage (.potx)',
        'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => 'PowerPoint-Show (.ppsx)',
        'application/msword' => '⚠ Word (.doc)',
        'application/vnd.ms-excel' => '⚠ Excel (.xls)',
        'application/vnd.ms-powerpoint' => '⚠ PowerPoint (.ppt)',
    ],
    'OpenDocument' => [
        'application/vnd.oasis.opendocument.text' => 'Writer (.odt)',
        'application/vnd.oasis.opendocument.spreadsheet' => 'Calc (.ods)',
        'application/vnd.oasis.opendocument.presentation' => 'Impress (.odp)',
    ],
    'Fonts' => [
        'font/woff' => 'WOFF',
        'font/woff2' => 'WOFF2',
        'font/ttf' => 'TrueType (.ttf)',
        'font/otf' => 'OpenType (.otf)',
    ],
];

// Alle bekannten MIME-Types sammeln
$knownTypes = [];
foreach ($typeGroups as $types) {
    foreach ($types as $mime => $label) {
        $knownTypes[] = $mime;
    }
}
// Unbekannte/eigene Types extrahieren (z.B. .pdf, .docx Endungen)
$customTypes = array_filter($currentTypes, static function ($t) use ($knownTypes) {
    return '' !== $t && !in_array($t, $knownTypes, true);
});

// Accordion-HTML aufbauen
$typesHtml = '<textarea class="form-control" id="filepond-allowed-types" name="allowed_types" rows="5" readonly style="margin-bottom:10px; cursor:default;">' . rex_escape($currentTypesValue) . '</textarea>';

$typesHtml .= '<div class="panel-group" id="filepond-types-accordion" role="tablist">';

$panelIndex = 0;
foreach ($typeGroups as $groupName => $types) {
    $panelId = 'filepond-type-panel-' . $panelIndex;
    $collapseId = 'filepond-type-collapse-' . $panelIndex;

    $activeCount = 0;
    foreach ($types as $mime => $label) {
        if (in_array($mime, $currentTypes, true)) {
            ++$activeCount;
        }
    }
    $badge = $activeCount > 0 ? ' <span class="badge">' . $activeCount . '</span>' : '';

    $typesHtml .= '<div class="panel panel-default">';
    $typesHtml .= '<div class="panel-heading" role="tab" id="' . $panelId . '">';
    $typesHtml .= '<h4 class="panel-title"><a role="button" data-toggle="collapse" data-parent="#filepond-types-accordion" href="#' . $collapseId . '" aria-expanded="false" aria-controls="' . $collapseId . '" style="text-decoration:none;">';
    $typesHtml .= rex_escape($groupName) . $badge;
    $typesHtml .= '</a></h4></div>';
    $typesHtml .= '<div id="' . $collapseId . '" class="panel-collapse collapse" role="tabpanel" aria-labelledby="' . $panelId . '">';
    $typesHtml .= '<div class="panel-body">';

    if ('Office' === $groupName) {
        $typesHtml .= '<div class="alert alert-warning" style="padding:6px 10px; margin-bottom:8px; font-size:12px;"><i class="rex-icon fa-exclamation-triangle"></i> Alte Office-Formate (.doc, .xls, .ppt) können Makros enthalten und stellen ein Sicherheitsrisiko dar. Wenn möglich, nur moderne Formate (.docx, .xlsx, .pptx) erlauben.</div>';
    }

    $typesHtml .= '<div class="row">';

    foreach ($types as $mime => $label) {
        $checked = in_array($mime, $currentTypes, true) ? ' checked' : '';
        $isWildcard = str_contains($mime, '/*');
        $style = $isWildcard ? ' style="font-weight:bold; margin:2px 0;"' : ' style="margin:2px 0;"';
        $typesHtml .= '<div class="col-sm-6 col-md-4"><div class="checkbox"' . $style . '><label><input type="checkbox" class="filepond-type-cb" value="' . rex_escape($mime) . '"' . $checked . '> ' . rex_escape($label) . '</label></div></div>';
    }

    $typesHtml .= '</div></div></div></div>';
    ++$panelIndex;
}

$typesHtml .= '</div>'; // panel-group

$typesHtml .= '<div style="margin-top: 8px;">';
$typesHtml .= '<label for="filepond-custom-types"><small>Eigene MIME-Types oder Endungen (kommagetrennt):</small></label>';
$typesHtml .= '<input class="form-control" type="text" id="filepond-custom-types" placeholder="z.B. .ics,.xml,text/calendar" value="' . rex_escape(implode(', ', $customTypes)) . '" />';
$typesHtml .= '</div>';

$form->addRawField('<div class="form-group"><label class="control-label">' . $addon->i18n('filepond_settings_allowed_types') . '</label>' . $typesHtml . '<p class="help-block">' . $addon->i18n('filepond_settings_allowed_types_notice') . '</p></div>');

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

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

// Debug-Logging aktivieren
$field = $form->addCheckboxField('enable_debug_logging');
$field->setLabel($addon->i18n('filepond_enable_debug_logging'));
$field->addOption($addon->i18n('filepond_enable_debug_logging_label'), 1);
$field->setNotice($addon->i18n('filepond_enable_debug_logging_notice'));

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
// 5. AI ALT-TEXT GENERIERUNG
// ============================================================================
$form->addFieldset($addon->i18n('filepond_ai_settings'));

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

// AI Provider Auswahl
$field = $form->addSelectField('ai_provider', null, [
    'class' => 'form-control',
    'id' => 'ai-provider-select'
]);
$field->setLabel($addon->i18n('filepond_settings_ai_provider'));
$select = $field->getSelect();
foreach (AltTextGenerator::PROVIDERS as $providerId => $providerName) {
    $select->addOption($providerName, $providerId);
}
$field->setNotice($addon->i18n('filepond_settings_ai_provider_notice'));

// Ziel-Feld für den AI-Zauberbutton im Upload-Modal
$field = $form->addTextField('ai_target_field', null, [
    'class' => 'form-control',
    'placeholder' => 'med_alt'
]);
$field->setLabel($addon->i18n('filepond_settings_ai_target_field'));
$field->setNotice($addon->i18n('filepond_settings_ai_target_field_notice'));

// Max Output Tokens
$field = $form->addInputField('number', 'ai_max_tokens', null, [
    'class' => 'form-control',
    'min' => '100',
    'max' => '8192',
    'placeholder' => '2048'
]);
$field->setLabel($addon->i18n('filepond_settings_ai_max_tokens'));
$field->setNotice($addon->i18n('filepond_settings_ai_max_tokens_notice'));

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

// === GEMINI SETTINGS ===
$form->addRawField('<div class="filepond-provider-block filepond-provider-gemini">');
$form->addRawField('<div class="row">');
$form->addRawField('<div class="col-sm-6">');

// Gemini API Key
$field = $form->addInputField('text', 'gemini_api_key', null, [
    'class' => 'form-control',
    'autocomplete' => 'off'
]);
$field->setAttribute('data-ai-provider-field', 'gemini');
$field->setLabel($addon->i18n('filepond_settings_gemini_api_key'));
$field->setNotice(sprintf($addon->i18n('filepond_settings_gemini_api_key_notice'), '<a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>'));

$form->addRawField('</div>');
$form->addRawField('<div class="col-sm-6">');

// Gemini Modell Auswahl
// Abgeschaltete Modelle erscheinen gleich mit ihrem Nachfolger vorbelegt,
// Speichern übernimmt ihn dann in die Konfiguration.
// Liste kommt live von Google (GeminiModelCatalog), ohne API-Key die Standardliste.
$geminiModels = \FriendsOfRedaxo\FilePond\GeminiModelCatalog::getModels();
$geminiModel = AltTextGenerator::getGeminiModel();
$field = $form->addSelectField('gemini_model', $geminiModel, [
    'class' => 'form-control selectpicker',
    'data-live-search' => 'true',
]);
$field->setAttribute('data-ai-provider-field', 'gemini');
$field->setLabel($addon->i18n('filepond_settings_gemini_model'));
$select = $field->getSelect();
foreach ($geminiModels as $modelId => $modelName) {
    $select->addOption($modelName, $modelId);
}
// Ein Modell, das nicht (mehr) in der Liste steht, nicht stillschweigend
// gegen den ersten Eintrag tauschen.
if (!isset($geminiModels[$geminiModel])) {
    $select->addOption($geminiModel, $geminiModel);
}
$geminiFetchedAt = \FriendsOfRedaxo\FilePond\GeminiModelCatalog::getFetchedAt();
$geminiSourceNotice = null !== $geminiFetchedAt
    ? $addon->i18n('filepond_settings_gemini_model_live', rex_formatter::intlDateTime($geminiFetchedAt, IntlDateFormatter::SHORT))
    : $addon->i18n('filepond_settings_gemini_model_fallback');
$field->setNotice($geminiSourceNotice . ' ' . $addon->i18n('filepond_settings_gemini_model_notice'));

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row



$form->addRawField('</div>'); // Ende gemini-settings

// === CLOUDFLARE SETTINGS ===
$form->addRawField('<div class="filepond-provider-block filepond-provider-cloudflare">');
$form->addRawField('<div class="row">');
$form->addRawField('<div class="col-sm-6">');

// Cloudflare API Token
$field = $form->addInputField('text', 'cloudflare_api_token', null, [
    'class' => 'form-control',
    'autocomplete' => 'off'
]);
$field->setAttribute('data-ai-provider-field', 'cloudflare');
$field->setLabel($addon->i18n('filepond_settings_cloudflare_token'));
$field->setNotice(sprintf($addon->i18n('filepond_settings_cloudflare_token_notice'), '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">Cloudflare Dashboard</a>'));

$form->addRawField('</div>');
$form->addRawField('<div class="col-sm-6">');

// Cloudflare Account ID
$field = $form->addInputField('text', 'cloudflare_account_id', null, [
    'class' => 'form-control',
    'autocomplete' => 'off'
]);
$field->setAttribute('data-ai-provider-field', 'cloudflare');
$field->setLabel($addon->i18n('filepond_settings_cloudflare_account_id'));
$field->setNotice(sprintf($addon->i18n('filepond_settings_cloudflare_account_id_notice'), '<a href="https://dash.cloudflare.com/" target="_blank">Workers &amp; Pages</a>'));

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row
$form->addRawField('</div>'); // Ende cloudflare-settings

// === OPENWEBUI SETTINGS ===
$form->addRawField('<div class="filepond-provider-block filepond-provider-openwebui">');
$form->addRawField('<div class="row">');

// Linke Spalte
$form->addRawField('<div class="col-sm-6">');

// Base URL
$field = $form->addInputField('text', 'openwebui_base_url', null, [
    'class' => 'form-control',
    'placeholder' => 'https://api.openai.com'
]);
$field->setAttribute('data-ai-provider-field', 'openwebui');
$field->setLabel($addon->i18n('filepond_settings_openwebui_base_url'));
$field->setNotice($addon->i18n('filepond_settings_openwebui_base_url_notice'));

$form->addRawField('</div>');
$form->addRawField('<div class="col-sm-6">');

// API Key
$field = $form->addInputField('text', 'openwebui_api_key', null, [
    'class' => 'form-control',
    'autocomplete' => 'off'
]);
$field->setAttribute('data-ai-provider-field', 'openwebui');
$field->setLabel($addon->i18n('filepond_settings_openwebui_api_key'));
$field->setNotice($addon->i18n('filepond_settings_openwebui_api_key_notice'));

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

$form->addRawField('<div class="row">');

// Linke Spalte
$form->addRawField('<div class="col-sm-6">');

// Model Name
$field = $form->addInputField('text', 'openwebui_model', null, [
    'class' => 'form-control',
    'placeholder' => 'llava'
]);
$field->setAttribute('data-ai-provider-field', 'openwebui');
$field->setLabel($addon->i18n('filepond_settings_openwebui_model'));
$field->setNotice($addon->i18n('filepond_settings_openwebui_model_notice'));

$form->addRawField('</div>');

$form->addRawField('</div>'); // Ende row
$form->addRawField('</div>'); // Ende openwebui-settings

$savedAiProvider = (string) rex_config::get('filepond_uploader', 'ai_provider', 'gemini');
$hasSavedAiConfig = [
    'gemini' => '' !== trim((string) rex_config::get('filepond_uploader', 'gemini_api_key', '')),
    'cloudflare' => '' !== trim((string) rex_config::get('filepond_uploader', 'cloudflare_api_token', ''))
        && '' !== trim((string) rex_config::get('filepond_uploader', 'cloudflare_account_id', '')),
    'openwebui' => '' !== trim((string) rex_config::get('filepond_uploader', 'openwebui_model', 'llava'))
        && (
            '' !== trim((string) rex_config::get('filepond_uploader', 'openwebui_api_key', ''))
            || '' !== trim((string) rex_config::get('filepond_uploader', 'openwebui_base_url', ''))
        ),
];

$isAiTestEnabled = $hasSavedAiConfig[$savedAiProvider] ?? false;

// API-Verbindungstest Button
$form->addRawField('
    <div class="form-group">
        <div style="margin-bottom: 10px;">
            <button type="button" class="btn btn-default" id="btn-test-ai-connection"' . ($isAiTestEnabled ? '' : ' disabled="disabled"') . '>
                <i class="fa fa-flask"></i> ' . $addon->i18n('filepond_settings_test_ai_connection') . '
            </button>
            <span id="ai-connection-result" style="margin-left: 10px;"></span>
        </div>
        <p class="help-block small"><i class="fa fa-info-circle"></i> ' . $addon->i18n('filepond_settings_test_connection_hint') . '</p>
        
        <div style="margin-top: 5px;">
            <a href="https://aistudio.google.com/usage?tab=rate-limit" target="_blank" class="btn btn-link" id="gemini-usage-link" title="' . $addon->i18n('filepond_settings_gemini_usage') . '">
                <i class="fa fa-external-link"></i> ' . $addon->i18n('filepond_settings_gemini_usage') . '
            </a>
            <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" class="btn btn-link" id="cloudflare-usage-link" style="display:none;" title="' . $addon->i18n('filepond_settings_cloudflare_usage') . '">
                <i class="fa fa-external-link"></i> ' . $addon->i18n('filepond_settings_cloudflare_usage') . '
            </a>
            <a href="https://docs.openwebui.com/" target="_blank" class="btn btn-link" id="openwebui-usage-link" style="display:none;" title="' . $addon->i18n('filepond_settings_openwebui_usage') . '">
                <i class="fa fa-external-link"></i> ' . $addon->i18n('filepond_settings_openwebui_usage') . '
            </a>
        </div>
    </div>
');

// ============================================================================
// 5. ANZEIGE-EINSTELLUNGEN
// ============================================================================
$form->addFieldset($addon->i18n('filepond_display_settings'));

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

// ============================================================================
// 6. API & SICHERHEIT
// ============================================================================
$form->addFieldset($addon->i18n('filepond_token_section'));

$form->addRawField('
    <div class="row">
        <div class="col-sm-8">
            <div class="form-group">
                <label class="control-label">' . $addon->i18n('filepond_current_token') . '</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="current-token" value="' . 
                    rex_escape(is_string($apiToken = rex_config::get('filepond_uploader', 'api_token')) ? $apiToken : '') . 
                    '" readonly>
                </div>
                <p class="help-block">' . $addon->i18n('filepond_token_help') . '</p>
            </div>
            
            <div class="form-group">
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="regenerate_token" value="1">
                        ' . $addon->i18n('filepond_regenerate_token') . '
                    </label>
                    <p class="help-block rex-warning">' . $addon->i18n('filepond_regenerate_token_warning') . '</p>
                </div>
            </div>
        </div>
    </div>
');

// ============================================================================
// 6. WARTUNG
// ============================================================================
$form->addFieldset($addon->i18n('filepond_maintenance_section'));

// Button zum Aufräumen temporärer Dateien
$form->addRawField('
    <div class="form-group">
        <label class="control-label">' . $addon->i18n('filepond_maintenance_cleanup') . '</label>
        <div>
            <button type="button" class="btn btn-default" id="cleanup-temp-files">
                <i class="fa fa-trash"></i> ' . $addon->i18n('filepond_maintenance_cleanup_button') . '
            </button>
            <span id="cleanup-status" class="help-block"></span>
        </div>
        <p class="help-block">' . $addon->i18n('filepond_maintenance_cleanup_notice') . '</p>
    </div>
    
    <script nonce="' . rex_response::getNonce() . '">
    document.addEventListener("DOMContentLoaded", function() {
        document.getElementById("cleanup-temp-files").addEventListener("click", function() {
            const statusEl = document.getElementById("cleanup-status");
            statusEl.textContent = "' . $addon->i18n('filepond_maintenance_cleanup_running') . '";
            
            fetch("' . rex_url::currentBackendPage() . '", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: "cleanup_temp=1"
            })
            .then(response => response.json())
            .then(data => {
                statusEl.textContent = data.message;
                setTimeout(() => {
                    statusEl.textContent = "";
                }, 5000);
            })
            .catch(error => {
                statusEl.textContent = "' . $addon->i18n('filepond_maintenance_cleanup_error') . '";
                console.error("Error:", error);
            });
        });
    });
    </script>
');

// Token Regenerierung behandeln
if (rex_post('regenerate_token', 'boolean')) {
    try {
        $token = bin2hex(random_bytes(32));
        rex_config::set('filepond_uploader', 'api_token', $token);
        echo rex_view::success($addon->i18n('filepond_token_regenerated') . '<br><br>' .
            '<div class="input-group">' .
            '<input type="text" class="form-control" id="new-token" value="' . rex_escape($token) . '" readonly>' .
            '<span class="input-group-btn">' .
            '<clipboard-copy for="new-token" class="btn btn-default"><i class="fa fa-clipboard"></i> ' . 
            $addon->i18n('filepond_copy_token') . '</clipboard-copy>' .
            '</span>' .
            '</div>');
    } catch (Exception $e) {
        echo rex_view::error($addon->i18n('filepond_token_regenerate_failed'));
    }
}

// AJAX-Aktion für Aufräumen temporärer Dateien
if (rex_request('cleanup_temp', 'boolean') && rex::isBackend() && rex::getUser() instanceof rex_user && rex::getUser()->isAdmin()) {
    $api = new Upload();
    try {
        $result = $api->handleCleanup();
        rex_response::cleanOutputBuffers();
        rex_response::sendJson($result);
        exit;
    } catch (Exception $e) {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
        rex_response::sendJson(['error' => $e->getMessage()]);
        exit;
    }
}

// Formular ausgeben
$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('filepond_settings_title'));
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');

// JavaScript für kombinierte Verarbeitungseinstellungen (nach Formularausgabe)
?>
<script nonce="<?= rex_response::getNonce() ?>">
(function() {
    let updateAiTestButtonState = null;

    function getAiProviderSelect() {
        return document.getElementById('ai-provider-select')
            || document.querySelector('select[name="ai_provider"]')
            || document.querySelector('select[name$="[ai_provider]"]');
    }

    function getProviderFields(provider) {
        const selectorMap = {
            gemini: [
                '[name="gemini_api_key"]',
                '[name$="[gemini_api_key]"]',
                '[id$="_gemini_api_key"]',
                '[name="gemini_model"]',
                '[name$="[gemini_model]"]',
                '[id$="_gemini_model"]',
            ],
            cloudflare: [
                '[name="cloudflare_api_token"]',
                '[name$="[cloudflare_api_token]"]',
                '[id$="_cloudflare_api_token"]',
                '[name="cloudflare_account_id"]',
                '[name$="[cloudflare_account_id]"]',
                '[id$="_cloudflare_account_id"]',
            ],
            openwebui: [
                '[name="openwebui_base_url"]',
                '[name$="[openwebui_base_url]"]',
                '[id$="_openwebui_base_url"]',
                '[name="openwebui_api_key"]',
                '[name$="[openwebui_api_key]"]',
                '[id$="_openwebui_api_key"]',
                '[name="openwebui_model"]',
                '[name$="[openwebui_model]"]',
                '[id$="_openwebui_model"]',
            ],
        };

        const selectors = selectorMap[provider] || [];
        const fields = [];

        selectors.forEach(function(selector) {
            document.querySelectorAll(selector).forEach(function(field) {
                if (!fields.includes(field)) {
                    fields.push(field);
                }
            });
        });

        return fields;
    }

    function toggleProviderFieldGroups(provider) {
        ['gemini', 'cloudflare', 'openwebui'].forEach(function(providerKey) {
            const visible = providerKey === provider;
            const fields = getProviderFields(providerKey);

            fields.forEach(function(field) {
                const group = field.closest('.rex-form-group, .form-group, dl');
                if (group) {
                    group.style.display = visible ? '' : 'none';
                } else {
                    field.style.display = visible ? '' : 'none';
                }
            });
        });
    }

    function initCombinedSettings() {
        const combinedSettings = document.getElementById("combined-processing-settings");
        if (!combinedSettings) {
            return;
        }
        
        // Finde Checkboxen über ID-Teilstring
        let clientCheckbox = null;
        let serverCheckbox = null;
        
        document.querySelectorAll("input[type=checkbox]").forEach(function(cb) {
            const cbId = cb.id || "";
            const cbName = cb.name || "";
            if (cbId.includes("create-thumbnails") || cbName.includes("create_thumbnails")) {
                clientCheckbox = cb;
            }
            if (cbId.includes("server-image-processing") || cbName.includes("server_image_processing")) {
                serverCheckbox = cb;
            }
        });
        
        function toggleCombinedSettings() {
            if (clientCheckbox && serverCheckbox && combinedSettings) {
                const bothActive = clientCheckbox.checked && serverCheckbox.checked;
                combinedSettings.style.display = bothActive ? "block" : "none";
            }
        }
        
        if (clientCheckbox) clientCheckbox.addEventListener("change", toggleCombinedSettings);
        if (serverCheckbox) serverCheckbox.addEventListener("change", toggleCombinedSettings);
        
        // Initial check
        toggleCombinedSettings();
    }
    
    // AI-Verbindungstest
    function initAiTest() {
        const testBtn = document.getElementById('btn-test-ai-connection');
        const resultSpan = document.getElementById('ai-connection-result');
        const providerSelect = getAiProviderSelect();
        const savedConfigMap = <?= json_encode($hasSavedAiConfig, JSON_THROW_ON_ERROR) ?>;
        const missingConfigMessage = <?= json_encode($addon->i18n('filepond_settings_test_connection_save_first'), JSON_THROW_ON_ERROR) ?>;
        const testButtonLabel = '<?= $addon->i18n('filepond_settings_test_ai_connection') ?>';
        
        if (!testBtn || !resultSpan) return;

        updateAiTestButtonState = function() {
            const provider = providerSelect ? providerSelect.value : '<?= rex_escape($savedAiProvider) ?>';
            const hasSavedConfig = !!savedConfigMap[provider];

            testBtn.disabled = !hasSavedConfig;

            if (!hasSavedConfig) {
                resultSpan.setAttribute('data-ai-state', 'missing-config');
                resultSpan.innerHTML = '<span class="text-muted"><i class="fa fa-info-circle"></i> ' + missingConfigMessage + '</span>';
            } else {
                if (resultSpan.getAttribute('data-ai-state') === 'missing-config') {
                    resultSpan.innerHTML = '';
                    resultSpan.removeAttribute('data-ai-state');
                }
            }
        };

        updateAiTestButtonState();
        
        // Gemini-Modellliste nach dem Test durch die frische Liste von Google ersetzen.
        function refreshGeminiModelOptions(models, suggestedModel) {
            const select = document.querySelector('select[name$="[gemini_model]"], select[name="gemini_model"]');
            if (!select) {
                return;
            }
            const current = select.value;
            const ids = Object.keys(models);
            select.innerHTML = '';
            ids.forEach((id) => {
                const opt = document.createElement('option');
                opt.value = id;
                opt.textContent = models[id];
                select.appendChild(opt);
            });
            if (current && ids.indexOf(current) === -1) {
                const opt = document.createElement('option');
                opt.value = current;
                opt.textContent = current;
                select.appendChild(opt);
            }
            select.value = suggestedModel && ids.indexOf(suggestedModel) !== -1 ? suggestedModel : current;
            if (window.jQuery && jQuery.fn.selectpicker) {
                jQuery(select).selectpicker('refresh');
            }
        }

        testBtn.addEventListener('click', function() {
            if (testBtn.disabled) {
                return;
            }

            testBtn.disabled = true;
            testBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Teste...';
            resultSpan.innerHTML = '';
            
            const apiUrl = '<?= rex_url::backendController([
                'rex-api-call' => 'filepond_ai_test'
            ]) ?>';
            
            fetch(apiUrl)
                .then(r => {
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(data => {
                    if (data && data.models && typeof data.models === 'object') {
                        refreshGeminiModelOptions(data.models, data.suggested_model || null);
                    }
                    if (data && data.success) {
                        resultSpan.setAttribute('data-ai-state', 'success');
                        resultSpan.innerHTML = '<span class="text-success"><i class="fa fa-check"></i> ' + (data.message || 'OK') + '</span>';
                    } else {
                        resultSpan.setAttribute('data-ai-state', 'error');
                        resultSpan.innerHTML = '<span class="text-danger"><i class="fa fa-times"></i> ' + (data?.message || data?.error || 'Unbekannter Fehler') + '</span>';
                    }
                })
                .catch(err => {
                    resultSpan.setAttribute('data-ai-state', 'error');
                    resultSpan.innerHTML = '<span class="text-danger"><i class="fa fa-times"></i> Fehler: ' + err.message + '</span>';
                })
                .finally(() => {
                    testBtn.innerHTML = '<i class="fa fa-flask"></i> ' + testButtonLabel;
                    if (updateAiTestButtonState) {
                        updateAiTestButtonState();
                    }
                });
        });
    }
    
    // AI Provider Toggle
    function initAiProviderToggle() {
        const providerSelect = getAiProviderSelect();
        if (!providerSelect) return;
        
        function toggleProviderSettings() {
            const provider = providerSelect.value;
            
            const geminiUsageLink = document.getElementById('gemini-usage-link');
            const cloudflareUsageLink = document.getElementById('cloudflare-usage-link');
            const openwebuiUsageLink = document.getElementById('openwebui-usage-link');
            
            if (geminiUsageLink) geminiUsageLink.style.display = 'none';
            if (cloudflareUsageLink) cloudflareUsageLink.style.display = 'none';
            if (openwebuiUsageLink) openwebuiUsageLink.style.display = 'none';
            
            if (provider === 'cloudflare') {
                if (cloudflareUsageLink) cloudflareUsageLink.style.display = 'inline';
            } else if (provider === 'openwebui') {
                if (openwebuiUsageLink) openwebuiUsageLink.style.display = 'inline';
            } else {
                if (geminiUsageLink) geminiUsageLink.style.display = 'inline';
            }

            toggleProviderFieldGroups(provider);

            if (updateAiTestButtonState) {
                updateAiTestButtonState();
            }
        }
        
        providerSelect.addEventListener('change', toggleProviderSettings);
        toggleProviderSettings(); // Initial
    }
    
    // Warte auf DOM ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function() {
            initCombinedSettings();
            initAiTest();
            initAiProviderToggle();
        });
    } else {
        initCombinedSettings();
        initAiTest();
        initAiProviderToggle();
    }
})();
</script>
<?php
