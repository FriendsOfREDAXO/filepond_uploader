<?php

/** @var rex_addon $this */

$addon = rex_addon::get('filepond_uploader');
$form = rex_config_form::factory('filepond_uploader');

// ============================================================================
// 0. ALLGEMEIN (WICHTIGSTE EINSTELLUNGEN)
// ============================================================================
$form->addFieldset($addon->i18n('filepond_general_settings'));

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
$typesHtml = '';

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

// Echtes Formularfeld (gespeichert mit CSRF-Prüfung des Formulars), befüllt über die Checkboxen
$field = $form->addTextAreaField('allowed_types', null, [
    'class' => 'form-control',
    'id' => 'filepond-allowed-types',
    'rows' => '4',
    'readonly' => 'readonly',
]);
$field->setLabel($addon->i18n('filepond_settings_allowed_types'));
$field->setNotice($addon->i18n('filepond_settings_allowed_types_notice'));
$form->addRawField($typesHtml);

$form->addRawField('</div>');
$form->addRawField('</div>'); // Ende row

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('filepond_settings_tab_upload'));
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');
