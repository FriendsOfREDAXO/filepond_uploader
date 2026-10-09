<?php

use FriendsOfRedaxo\FilePondUploader\YcomAuthSettings;
// Ausgewählte Kategorie hat Vorrang vor der Einstellung aus der Config
$selectedCategory = rex_request('category_id', 'int', 0);

// Opener Input Field für Media Widget Integration
$openerInputField = rex_request('opener_input_field', 'string', '');
$isMediaWidget = !empty($openerInputField);

$selMedia = new rex_media_category_select($checkPerm = true);
$selMedia->setId('rex-mediapool-category');
$selMedia->setName('category_id');
$selMedia->setSize(1);
$selMedia->setSelected($selectedCategory);
$selMedia->setAttribute('class', 'selectpicker');
$selMedia->setAttribute('data-live-search', 'true');
$mediaPerm = rex::getUser() ? rex::getUser()->getComplexPerm('media') : null;
if ($mediaPerm instanceof rex_media_perm && $mediaPerm->hasAll()) {
    $selMedia->addOption(rex_i18n::msg('filepond_upload_no_category'), '0');
} elseif ($selectedCategory === 0) {
    // Eingeschränkter User ohne explizit gesetzter Kategorie:
    // erste verfügbare Kategorie automatisch vorauswählen
    $rootCats = rex_media_category::getRootCategories();
    foreach ($rootCats as $cat) {
        if (!($mediaPerm instanceof rex_media_perm) || $mediaPerm->hasCategoryPerm($cat->getId())) {
            $selectedCategory = $cat->getId();
            $selMedia->setSelected($selectedCategory);
            break;
        }
    }
}

$currentUser = rex::getUser();
$langCodeVal = $currentUser ? $currentUser->getLanguage() : rex_config::get('filepond_uploader', 'lang', 'en_gb');
$langCode = is_string($langCodeVal) ? $langCodeVal : 'en_gb';

// Prüfen, ob Metadaten übersprungen werden sollen (neue Einstellung)
$skipMeta = rex_config::get('filepond_uploader', 'upload_skip_meta', false);

// Prüfen, ob verzögerter Upload-Modus aktiviert ist
$delayedUpload = rex_config::get('filepond_uploader', 'delayed_upload_mode', false);

// Prüfen, ob title/alt im Metadialog required sein sollen
$titleRequired = false;
$altRequired = true;

// Config-Werte für data-Attribute vorab typsicher extrahieren
$cfgMaxFiles = rex_config::get('filepond_uploader', 'max_files', 30);
$dataMaxFiles = is_numeric($cfgMaxFiles) ? (string) (int) $cfgMaxFiles : '30';
$cfgAllowedTypes = rex_config::get('filepond_uploader', 'allowed_types', 'image/*,video/*,.pdf,.doc,.docx,.txt');
$dataAllowedTypes = is_string($cfgAllowedTypes) ? $cfgAllowedTypes : 'image/*,video/*,.pdf,.doc,.docx,.txt';
$cfgMaxFilesize = rex_config::get('filepond_uploader', 'max_filesize', 10);
$dataMaxFilesize = is_numeric($cfgMaxFilesize) ? (string) (int) $cfgMaxFilesize : '10';
$cfgClientMaxPixel = rex_config::get('filepond_uploader', 'client_max_pixel', '');
$cfgMaxPixel = rex_config::get('filepond_uploader', 'max_pixel', 2100);
$dataMaxPixel = is_scalar($cfgClientMaxPixel) && $cfgClientMaxPixel !== '' ? (string) $cfgClientMaxPixel : (is_numeric($cfgMaxPixel) ? (string) (int) $cfgMaxPixel : '2100');
$cfgClientQuality = rex_config::get('filepond_uploader', 'client_image_quality', '');
$cfgQuality = rex_config::get('filepond_uploader', 'image_quality', 90);
$dataQuality = is_scalar($cfgClientQuality) && $cfgClientQuality !== '' ? (string) $cfgClientQuality : (is_numeric($cfgQuality) ? (string) (int) $cfgQuality : '90');
$cfgCreateThumbnails = rex_config::get('filepond_uploader', 'create_thumbnails', '');
$dataClientResize = (is_string($cfgCreateThumbnails) && $cfgCreateThumbnails === '|1|') ? 'true' : 'false';
$isEnabledConfig = static function (string $key, bool $default): bool {
    $raw = rex_config::get('filepond_uploader', $key, $default ? '1' : '0');

    return in_array($raw, [1, '1', true, 'true', '|1|'], true);
};

$titleRequired = $isEnabledConfig('title_required_default', false);
$altRequired = $isEnabledConfig('alt_required_default', true);

$cfgAiEnabled = $isEnabledConfig('enable_ai_alt', false)
    && $isEnabledConfig('enable_ai_upload_modal', true);
$dataAiEnabled = $cfgAiEnabled ? 'true' : 'false';
$cfgAiTargetFieldVal = rex_config::get('filepond_uploader', 'ai_target_field', 'med_alt');
$dataAiTargetField = is_string($cfgAiTargetFieldVal) && '' !== trim($cfgAiTargetFieldVal) ? trim($cfgAiTargetFieldVal) : 'med_alt';

// YCom Media Auth Defaults Panel (optional, gegated)
$ycomAuthHtml = '';
if (YcomAuthSettings::isEnabled()
    && YcomAuthSettings::userMayManage(rex::getUser())) {
    $fpAddon = rex_addon::get('filepond_uploader');
    $ycomDefaults = YcomAuthSettings::getSessionDefaults();
    $hasGroupSupport = YcomAuthSettings::isGroupSupportAvailable();

    // Auth-Typ Select
    $authSel = new rex_select();
    $authSel->setName('ycom_auth_type');
    $authSel->setId('filepond-ycom-auth-type');
    $authSel->setSize(1);
    $authSel->setAttribute('class', 'form-control');
    $authSel->addArrayOptions([
        0 => $fpAddon->i18n('filepond_ycom_auth_all'),
        1 => $fpAddon->i18n('filepond_ycom_auth_only_logged_in'),
    ]);
    $authSel->setSelected($ycomDefaults['ycom_auth_type']);

    $rowAuth = '<div class="form-group">'
        . '<label for="filepond-ycom-auth-type">' . rex_escape($fpAddon->i18n('filepond_ycom_auth_type')) . '</label>'
        . $authSel->get()
        . '<p class="help-block text-muted">' . rex_escape($fpAddon->i18n('filepond_ycom_auth_type_note')) . '</p>'
        . '</div>';

    $rowGroupType = '';
    $rowGroups = '';
    if ($hasGroupSupport) {
        $groupTypeSel = new rex_select();
        $groupTypeSel->setName('ycom_group_type');
        $groupTypeSel->setId('filepond-ycom-group-type');
        $groupTypeSel->setSize(1);
        $groupTypeSel->setAttribute('class', 'form-control');
        $groupTypeSel->addArrayOptions([
            0 => rex_i18n::msg('ycom_group_forallgroups'),
            1 => rex_i18n::msg('ycom_group_inallgroups'),
            2 => rex_i18n::msg('ycom_group_inonegroup'),
            3 => rex_i18n::msg('ycom_group_nogroups'),
        ]);
        $groupTypeSel->setSelected($ycomDefaults['ycom_group_type']);

        $rowGroupType = '<div class="form-group" id="filepond-ycom-group-type-row">'
            . '<label for="filepond-ycom-group-type">' . rex_escape($fpAddon->i18n('filepond_ycom_group_type')) . '</label>'
            . $groupTypeSel->get()
            . '</div>';

        $ycomGroups = [];
        if (class_exists(rex_ycom_group::class)) {
            try {
                $ycomGroups = rex_ycom_group::getGroups();
            } catch (\Throwable $e) {
                $ycomGroups = [];
            }
        }

        $groupSel = new rex_select();
        $groupSel->setName('ycom_groups[]');
        $groupSel->setId('filepond-ycom-groups');
        $groupSel->setMultiple();
        $groupSel->setSize(min(8, max(3, count($ycomGroups))));
        $groupSel->setAttribute('class', 'form-control');
        foreach ($ycomGroups as $gid => $gname) {
            $groupSel->addOption($gname, (string) $gid);
        }
        if (!empty($ycomDefaults['ycom_groups'])) {
            $groupSel->setSelected($ycomDefaults['ycom_groups']);
        }

        $rowGroups = '<div class="form-group" id="filepond-ycom-groups-row">'
            . '<label for="filepond-ycom-groups">' . rex_escape($fpAddon->i18n('filepond_ycom_groups')) . '</label>'
            . $groupSel->get()
            . '<p class="help-block text-muted">' . rex_escape($fpAddon->i18n('filepond_ycom_groups_note')) . '</p>'
            . '</div>';
    }

    // Status-Badge: zeigt aktuell aktive Defaults
    $badgeText = 0 === $ycomDefaults['ycom_auth_type']
        ? $fpAddon->i18n('filepond_ycom_auth_all')
        : $fpAddon->i18n('filepond_ycom_auth_only_logged_in');
    $badgeClass = 0 === $ycomDefaults['ycom_auth_type'] ? 'label-default' : 'label-warning';
    $statusBadge = '<span id="filepond-ycom-auth-status" class="label ' . $badgeClass . '" data-public-text="'
        . rex_escape($fpAddon->i18n('filepond_ycom_auth_all'))
        . '" data-protected-text="' . rex_escape($fpAddon->i18n('filepond_ycom_auth_only_logged_in')) . '">'
        . rex_escape($badgeText) . '</span>';

    $ycomAuthHtml = '
    <div class="panel panel-default" id="filepond-ycom-auth-panel" style="margin-top:0; margin-bottom:0; border-radius:0; border-left:none; border-right:none;">
        <div class="panel-heading" style="display:flex; align-items:center; gap:8px;">
            <a role="button" data-toggle="collapse" href="#filepond-ycom-auth-body" aria-expanded="false" aria-controls="filepond-ycom-auth-body" class="collapsed" style="flex:1;">
                <i class="rex-icon fa-solid fa-lock"></i> ' . rex_escape($fpAddon->i18n('filepond_ycom_auth_panel_title')) . '
            </a>
            ' . $statusBadge . '
            <button type="button" id="filepond-ycom-auth-reset" class="btn btn-default btn-xs" title="' . rex_escape($fpAddon->i18n('filepond_ycom_auth_reset')) . '">
                <i class="rex-icon fa-solid fa-rotate-left"></i>
            </button>
        </div>
        <div id="filepond-ycom-auth-body" class="panel-collapse collapse" aria-expanded="false">
            <div class="panel-body">
                <p class="text-muted">' . rex_escape($fpAddon->i18n('filepond_ycom_auth_panel_intro')) . '</p>
                ' . $rowAuth . $rowGroupType . $rowGroups . '
            </div>
        </div>
    </div>';
}

$content = '
<div class="rex-form">
    <form action="' . rex_url::currentBackendPage() . '" method="post" class="form-horizontal">
        <div class="panel panel-default">
            <div class="panel-heading">
                <div class="panel-title">' . rex_i18n::msg('filepond_upload_title') . '</div>
            </div>
            
            <div class="panel-body">
                <div class="form-group">
                    <label class="col-sm-2 control-label">' . rex_i18n::msg('filepond_upload_category') . '</label>
                    <div class="col-sm-10">
                        '.$selMedia->get().'
                    </div>
                </div>
                ' . $ycomAuthHtml . '
                <div class="form-group" style="margin-top:15px;">
                    <label class="col-sm-2 control-label">' . rex_i18n::msg('filepond_upload_files') . '</label>
                    <div class="col-sm-10">
                        <input type="hidden" 
                            id="filepond-upload"
                            data-widget="filepond"
                            data-filepond-cat="'.$selectedCategory.'"
                            data-filepond-maxfiles="'.$dataMaxFiles.'"
                            data-filepond-types="'.$dataAllowedTypes.'"
                            data-filepond-maxsize="'.$dataMaxFilesize.'"
                            data-filepond-lang="'.$langCode.'"
                            data-filepond-skip-meta="'.($skipMeta ? 'true' : 'false').'"
                            data-filepond-delayed-upload="'.($delayedUpload ? 'true' : 'false').'"
                            data-filepond-title-required="'.($titleRequired ? 'true' : 'false').'"
                            data-filepond-alt-required="'.($altRequired ? 'true' : 'false').'"
                            data-filepond-opener-field="'.rex_escape($openerInputField).'"
                            data-filepond-max-pixel="'.$dataMaxPixel.'" 
                            data-filepond-image-quality="'.$dataQuality.'" 
                            data-filepond-client-resize="'.$dataClientResize.'"
                            data-filepond-ai-enabled="'.$dataAiEnabled.'"
                            data-filepond-ai-target-field="'.rex_escape($dataAiTargetField).'"
                            value=""
                        >
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
';?>
<script nonce="<?= rex_response::getNonce() ?>">
$(document).on("rex:ready", function() {
    
    // Initialsynchronisation: data-filepond-cat mit dem aktuell gewählten Select-Wert abgleichen.
    // Nötig wenn ein eingeschränkter User keine "Keine Kategorie"-Option hat und
    // der initiale data-filepond-cat-Wert 0 wäre, obwohl der Select bereits etwas auswählt.
    (function() {
        var initCatVal = $("#rex-mediapool-category").val();
        if (initCatVal !== null && initCatVal !== undefined && initCatVal !== '') {
            $("#filepond-upload").attr("data-filepond-cat", initCatVal);
        }
    })();

    $("#rex-mediapool-category").on("change", function() {
        const newCategory = $(this).val();
        const $input = $("#filepond-upload");
        $input.attr("data-filepond-cat", newCategory);
    });
});
</script>

<?php 
// Media Widget Integration JavaScript mit Nonce
if ($isMediaWidget): ?>
<script nonce="<?= rex_response::getNonce() ?>">
// FilePond Media Widget Integration
(function() {
    'use strict';
    
    const MediaWidget = {
        openerInputField: '<?= rex_escape($openerInputField, 'js') ?>',
        isMediaList: <?= str_starts_with($openerInputField, 'REX_MEDIALIST_') ? 'true' : 'false' ?>,
        resultsContainer: null,
        
        init() {
            
            const start = () => {
                this.createInfoBanner();
                this.startUploadMonitoring();
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', start);
            } else {
                start();
            }
        },
        
        createInfoBanner() {
            const banner = document.createElement('div');
            banner.className = 'alert alert-info';
            banner.innerHTML = `
                <h4><i class="fa fa-info-circle"></i> <?= rex_i18n::msg('filepond_uploader_media_widget_mode') ?></h4>
                <p><?= rex_i18n::msg('filepond_uploader_media_widget_info') ?></p>
            `;
            
            const mainContent = document.querySelector('.rex-page-section');
            if (mainContent) {
                mainContent.insertBefore(banner, mainContent.firstChild);
            }
        },
        
        createResultsContainer() {
            if (this.resultsContainer) return this.resultsContainer;
            
            this.resultsContainer = document.createElement('div');
            this.resultsContainer.className = 'panel panel-success fp-results-container';
            this.resultsContainer.innerHTML = `
                <div class="panel-heading">
                    <h4 class="panel-title">
                        <i class="fa fa-check-circle"></i> Hochgeladene Dateien
                    </h4>
                </div>
                <div class="panel-body">
                    <ul id="filepond-uploaded-files" class="list-unstyled"></ul>
                    ${this.isMediaList ? `
                        <div id="filepond-bulk-actions" class="fp-bulk-actions">
                            <button type="button" class="btn btn-primary btn-sm" id="filepond-select-all">
                                <i class="fa fa-download"></i> Alle Dateien in Medienliste übernehmen
                            </button>
                            <small class="text-muted fp-bulk-actions-text">
                                Übernimmt alle hochgeladenen Dateien auf einmal
                            </small>
                        </div>
                    ` : ''}
                </div>
            `;
            
            const uploadPanel = document.querySelector('.panel-edit');
            if (uploadPanel && uploadPanel.parentNode) {
                uploadPanel.parentNode.appendChild(this.resultsContainer);
            }
            
            // "Alle übernehmen" Button-Handler für Medialists
            if (this.isMediaList) {
                const selectAllButton = this.resultsContainer.querySelector('#filepond-select-all');
                if (selectAllButton) {
                    selectAllButton.addEventListener('click', (e) => {
                        e.preventDefault();
                        this.selectAllMedia();
                    });
                }
            }
            
            return this.resultsContainer;
        },
        
        isImageFile(filename) {
            const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff'];
            const extension = filename.split('.').pop().toLowerCase();
            return imageExtensions.includes(extension);
        },
        
        getMediaUrl(filename) {
            const baseUrl = window.location.origin;
            const redaxoPath = window.location.pathname.split('/redaxo/')[0];
            return `${baseUrl}${redaxoPath}/media/${filename}`;
        },
        
        createImagePreview(filename) {
            const mediaUrl = this.getMediaUrl(filename);
            return `
                <div class="fp-preview-container">
                    <img src="${mediaUrl}" 
                         alt="${filename}" 
                         class="fp-preview-image"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    />
                    <div class="fp-image-preview-fallback">
                        <i class="fa fa-image text-muted"></i>
                    </div>
                </div>
            `;
        },
        
        createFileIcon(filename) {
            const extension = filename.split('.').pop().toLowerCase();
            let iconClass = 'fa-file';
            let iconColor = '#6c757d';
            
            switch(extension) {
                case 'pdf':
                    iconClass = 'fa-file-pdf-o';
                    iconColor = '#dc3545';
                    break;
                case 'doc':
                case 'docx':
                    iconClass = 'fa-file-word-o';
                    iconColor = '#007bff';
                    break;
                case 'xls':
                case 'xlsx':
                    iconClass = 'fa-file-excel-o';
                    iconColor = '#28a745';
                    break;
                case 'ppt':
                case 'pptx':
                    iconClass = 'fa-file-powerpoint-o';
                    iconColor = '#fd7e14';
                    break;
                case 'zip':
                case 'rar':
                case '7z':
                    iconClass = 'fa-file-archive-o';
                    iconColor = '#6f42c1';
                    break;
                case 'mp4':
                case 'avi':
                case 'mov':
                case 'wmv':
                    iconClass = 'fa-file-video-o';
                    iconColor = '#e83e8c';
                    break;
                case 'mp3':
                case 'wav':
                case 'flac':
                    iconClass = 'fa-file-audio-o';
                    iconColor = '#17a2b8';
                    break;
                case 'txt':
                    iconClass = 'fa-file-text-o';
                    iconColor = '#6c757d';
                    break;
            }
            
            return `
                <div class="fp-file-icon">
                    <i class="fa ${iconClass}" style="color: ${iconColor};"></i>
                    <small>${extension}</small>
                </div>
            `;
        },
        
        handleUploadSuccess(filename) {
            
            const container = this.createResultsContainer();
            const filesList = container.querySelector('#filepond-uploaded-files');
            
            if (filesList) {
                const listItem = document.createElement('li');
                listItem.className = 'fp-media-upload-result-extended';
                listItem.dataset.filename = filename; // Für "Alle übernehmen" Funktion
                
                const buttonText = this.isMediaList ? 'In Medienliste übernehmen' : 'Übernehmen';
                const isImage = this.isImageFile(filename);
                const previewHtml = isImage ? this.createImagePreview(filename) : this.createFileIcon(filename);
                
                // Build DOM tree safely to avoid XSS
                const rowDiv = document.createElement('div');
                rowDiv.className = 'row';
                
                const colPreview = document.createElement('div');
                colPreview.className = 'col-sm-2';
                colPreview.innerHTML = previewHtml; // previewHtml is created by safe methods
                
                const colInfo = document.createElement('div');
                colInfo.className = 'col-sm-6';
                
                const strongEl = document.createElement('strong');
                const iconEl = document.createElement('i');
                iconEl.className = 'fa fa-file';
                strongEl.appendChild(iconEl);
                strongEl.appendChild(document.createTextNode(' ' + filename));
                colInfo.appendChild(strongEl);
                colInfo.appendChild(document.createElement('br'));
                
                const smallEl = document.createElement('small');
                smallEl.className = 'text-muted';
                smallEl.textContent = 'Erfolgreich hochgeladen';
                colInfo.appendChild(smallEl);
                
                if (isImage) {
                    colInfo.appendChild(document.createElement('br'));
                    const imageInfo = document.createElement('small');
                    imageInfo.className = 'text-info';
                    const imageIcon = document.createElement('i');
                    imageIcon.className = 'fa fa-image';
                    imageInfo.appendChild(imageIcon);
                    imageInfo.appendChild(document.createTextNode(' Bilddatei'));
                    colInfo.appendChild(imageInfo);
                }
                
                const colButton = document.createElement('div');
                colButton.className = 'col-sm-4 text-right';
                
                const buttonEl = document.createElement('button');
                buttonEl.type = 'button';
                buttonEl.className = 'btn btn-success btn-sm filepond-select-media';
                buttonEl.setAttribute('data-filename', filename);
                const buttonIcon = document.createElement('i');
                buttonIcon.className = 'fa fa-check';
                buttonEl.appendChild(buttonIcon);
                buttonEl.appendChild(document.createTextNode(' ' + buttonText));
                colButton.appendChild(buttonEl);
                
                rowDiv.appendChild(colPreview);
                rowDiv.appendChild(colInfo);
                rowDiv.appendChild(colButton);
                
                listItem.appendChild(rowDiv);
                
                filesList.appendChild(listItem);
                
                // Button-Handler hinzufügen
                const selectButton = listItem.querySelector('.filepond-select-media');
                selectButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    const filename = e.target.dataset.filename;
                    
                    
                    if (this.isMediaList) {
                        this.selectMedialist(filename);
                    } else {
                        this.selectMedia(filename, '');
                    }
                });
                
                // "Alle übernehmen" Button anzeigen wenn mehr als eine Datei und Medialist
                if (this.isMediaList) {
                    const bulkActions = container.querySelector('#filepond-bulk-actions');
                    const allFiles = filesList.querySelectorAll('li[data-filename]');
                    if (bulkActions && allFiles.length > 1) {
                        bulkActions.style.display = 'block';
                    }
                }
            }
        },
        
        selectMedia(filename, alt = '') {
            
            if (!window.opener) {
                console.error('Opener-Fenster nicht gefunden!');
                return;
            }
            
            try {
                const input = window.opener.document.getElementById(this.openerInputField);
                if (input) {
                    input.value = filename;
                    
                    if (window.opener.jQuery) {
                        window.opener.jQuery(input).trigger('change');
                    } else {
                        const event = new Event('change', { bubbles: true });
                        input.dispatchEvent(event);
                    }
                    
                    window.close(); // Nur bei Einzelmedien schließen
                } else {
                    console.error('Input-Feld nicht gefunden:', this.openerInputField);
                }
            } catch (error) {
                console.error('Error in selectMedia:', error);
            }
        },
        
        selectMedialist(filename) {
            
            if (!window.opener) {
                console.error('Opener-Fenster nicht gefunden!');
                return;
            }
            
            try {
                const openerId = this.openerInputField.slice('REX_MEDIALIST_'.length);
                const medialist = 'REX_MEDIALIST_SELECT_' + openerId;
                
                const source = window.opener.document.getElementById(medialist);
                if (source) {
                    // Prüfen ob Datei bereits in der Liste ist
                    const existingOption = Array.from(source.options).find(option => option.value === filename);
                    if (!existingOption) {
                        const option = window.opener.document.createElement('OPTION');
                        option.text = filename;
                        option.value = filename;
                        
                        source.options.add(option, source.options.length);
                        
                        if (window.opener.writeREXMedialist) {
                            window.opener.writeREXMedialist(openerId);
                        }
                        
                        
                        // Button als "hinzugefügt" markieren
                        const button = document.querySelector(`button[data-filename="${filename}"]`);
                        if (button) {
                            button.innerHTML = '<i class="fa fa-check"></i> Hinzugefügt';
                            button.className = 'btn btn-default btn-sm';
                            button.disabled = true;
                        }
                    } else {
                    }
                    
                    // Fenster NICHT schließen bei Medialists
                } else {
                    console.error('Medienliste nicht gefunden:', medialist);
                }
            } catch (error) {
                console.error('Error in selectMedialist:', error);
            }
        },
        
        selectAllMedia() {
            
            if (!window.opener) {
                console.error('Opener-Fenster nicht gefunden!');
                return;
            }
            
            const filesList = document.querySelector('#filepond-uploaded-files');
            if (!filesList) {
                console.error('Keine Dateien zum Übernehmen gefunden!');
                return;
            }
            
            const allFiles = filesList.querySelectorAll('li[data-filename]');
            if (allFiles.length === 0) {
                console.error('Keine Dateien zum Übernehmen gefunden!');
                return;
            }
            
            try {
                const openerId = this.openerInputField.slice('REX_MEDIALIST_'.length);
                const medialist = 'REX_MEDIALIST_SELECT_' + openerId;
                
                const source = window.opener.document.getElementById(medialist);
                if (!source) {
                    console.error('Medienliste nicht gefunden:', medialist);
                    return;
                }
                
                let addedCount = 0;
                const addedFiles = [];
                
                allFiles.forEach(fileItem => {
                    const filename = fileItem.dataset.filename;
                    if (filename) {
                        // Prüfen ob Datei bereits in der Liste ist
                        const existingOption = Array.from(source.options).find(option => option.value === filename);
                        if (!existingOption) {
                            const option = window.opener.document.createElement('OPTION');
                            option.text = filename;
                            option.value = filename;
                            source.options.add(option, source.options.length);
                            addedCount++;
                            addedFiles.push(filename);
                            
                            // Button als "hinzugefügt" markieren
                            const button = fileItem.querySelector(`button[data-filename="${filename}"]`);
                            if (button) {
                                button.innerHTML = '<i class="fa fa-check"></i> Hinzugefügt';
                                button.className = 'btn btn-default btn-sm';
                                button.disabled = true;
                            }
                        }
                    }
                });
                
                if (addedCount > 0) {
                    if (window.opener.writeREXMedialist) {
                        window.opener.writeREXMedialist(openerId);
                    }
                    
                    
                    // "Alle übernehmen" Button deaktivieren
                    const selectAllButton = document.querySelector('#filepond-select-all');
                    if (selectAllButton) {
                        selectAllButton.innerHTML = '<i class="fa fa-check"></i> Alle hinzugefügt';
                        selectAllButton.className = 'btn btn-default btn-sm';
                        selectAllButton.disabled = true;
                    }
                    
                    // Fenster NICHT schließen
                } else {
                }
                
            } catch (error) {
                console.error('Error in selectAllMedia:', error);
            }
        },
        
        startUploadMonitoring() {
            // Das Widget meldet jede fertig hochgeladene Datei mit ihrem Medienpool-Namen
            document.addEventListener('filepond:uploaded', (event) => {
                if (event.detail && event.detail.filename) {
                    this.handleUploadSuccess(event.detail.filename);
                }
            });
        }
    };
    
    // Initialisierung
    MediaWidget.init();
})();
</script>
<?php endif; ?>

<?php 
// Fragment ausgeben
$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('filepond_upload_title'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
