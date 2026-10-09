<?php

use FriendsOfRedaxo\FilePondUploader\Ai\AltTextGenerator;
use FriendsOfRedaxo\FilePondUploader\AltTextChecker;
use FriendsOfRedaxo\FilePondUploader\Config;
use FriendsOfRedaxo\FilePondUploader\Helper;
/**
 * Alt-Text-Checker - Bilder ohne Alt-Text finden und bearbeiten
 * 
 * @package filepond_uploader
 */

// Berechtigung prüfen
if (!rex::getUser() || (!rex::getUser()->isAdmin() && !rex::getUser()->hasPerm('filepond_uploader[alt_checker]'))) {
    echo rex_view::error(rex_i18n::msg('no_perm'));
    return;
}

$addon = rex_addon::get('filepond_uploader');
$magicIconUrl = $addon->getAssetsUrl('icons/magic.svg');
$magicIconHtml = '<img src="' . rex_escape($magicIconUrl) . '" class="filepond-magic-icon" alt="" aria-hidden="true">';

// Filter und Pagination zuerst definieren
$configItemsPerPage = $addon->getConfig('items_per_page');
$itemsPerPage = is_numeric($configItemsPerPage) ? (int) $configItemsPerPage : 30;
if ($itemsPerPage < 1) {
    $itemsPerPage = 30;
}

$filterFilename = rex_request('filter_filename', 'string', '');
$filterCategory = rex_request('filter_category', 'int', -1);

// Media Category Select für Filter - wie auf der Upload-Seite
$selMediaFilter = new rex_media_category_select($checkPerm = true);
$selMediaFilter->setId('filter_category');
$selMediaFilter->setName('filter_category');
$selMediaFilter->setSize(1);
$selMediaFilter->setSelected($filterCategory);
$selMediaFilter->setAttribute('class', 'form-control');
$selMediaFilter->setAttribute('onchange', 'this.form.submit(); return false;');
$selMediaFilter->addOption($addon->i18n('alt_checker_all_categories'), '-1');
$mediaPerm = rex::getUser()->getComplexPerm('media');
if ($mediaPerm->hasAll()) {
    $selMediaFilter->addOption(rex_i18n::msg('pool_kats_no'), '0');
}

// API Endpoint
// Unescaped, die URL landet per json_encode im JavaScript
$apiEndpoint = rex_url::backendController([
    'rex-api-call' => 'filepond_alt_checker',
    '_csrf_token' => Helper::csrfToken(),
], false);

// Prüfen ob med_alt Feld existiert
$altFieldExists = AltTextChecker::checkAltFieldExists();

// AI-Status prüfen
$aiEnabled = AltTextGenerator::isEnabled();

// Mehrsprachigkeit prüfen
$isMultiLang = AltTextChecker::isMultiLangField();
$languages = [];
// Sprachen immer laden, auch für einsprachige Seiten (für AI-Generierung)
foreach (rex_clang::getAll() as $clang) {
    $languages[$clang->getId()] = [
        'id' => $clang->getId(),
        'code' => $clang->getCode(),
        'name' => $clang->getName()
    ];
}
$currentLangId = rex_clang::getCurrentId();

$fallbackLangCode = Config::aiFallbackLanguage();
$blockedCodes = array_values(array_diff(Config::aiBlockedLanguages(), [$fallbackLangCode]));

$languageCodeToName = [];
foreach ($languages as $lang) {
    $langCode = strtolower(substr($lang['code'], 0, 2));
    $langName = $lang['name'];
    if ('' !== $langCode && !isset($languageCodeToName[$langCode])) {
        $languageCodeToName[$langCode] = $langName;
    }
}

$blockedLabelParts = [];
foreach ($blockedCodes as $blockedCode) {
    $blockedName = $languageCodeToName[$blockedCode] ?? strtoupper($blockedCode);
    $blockedLabelParts[] = $blockedName . ' (' . strtoupper($blockedCode) . ')';
}
$fallbackName = $languageCodeToName[$fallbackLangCode] ?? strtoupper($fallbackLangCode);

// Filter und Pagination (bereits oben definiert)
$filters = [];
if (!empty($filterFilename)) {
    $filters['filename'] = $filterFilename;
}
if ($filterCategory >= 0) {
    $filters['category_id'] = $filterCategory;
}

// Statistik laden
$stats = AltTextChecker::getStatistics();

// Bilder laden
$totalCount = 0;
$images = [];
$pager = new rex_pager($itemsPerPage, 'start');

if ($altFieldExists) {
    $totalCount = AltTextChecker::countImagesWithoutAlt($filters);
    $pager->setRowCount($totalCount);
    $offset = $pager->getCursor();
    $images = AltTextChecker::findImagesWithoutAlt($filters, $itemsPerPage, $offset);
}

// Determine current page context (mediapool or addon)
$currentPage = rex_be_controller::getCurrentPage();
?>

<div id="alt-checker-app">
    <?php if (!$altFieldExists): ?>
    <div class="alert alert-warning">
        <h4><i class="fa fa-exclamation-triangle"></i> <?= $addon->i18n('alt_checker_field_missing_title') ?></h4>
        <p><?= $addon->i18n('alt_checker_field_missing_text') ?></p>
        <p>
            <a href="<?= rex_url::backendPage('metainfo/media') ?>" class="btn btn-warning">
                <i class="fa fa-cog"></i> <?= $addon->i18n('alt_checker_create_field') ?>
            </a>
        </p>
    </div>
    <?php else: ?>
    
    <!-- Statistik-Header -->
    <?php 
    $showStats = $addon->getConfig('show_alt_stats', '');
    if ($showStats === '|1|' || $showStats === '1'): 
    ?>
    <div class="panel panel-default" id="stats-panel">
        <div class="panel-heading">
            <div class="panel-title">
                <i class="fa fa-pie-chart fa-fw"></i> 
                <?= $addon->i18n('alt_checker_statistics') ?>
            </div>
        </div>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-3 text-center">
                    <div class="stat-box">
                        <div class="stat-value" id="stat-total"><?= $stats['total'] ?></div>
                        <div class="stat-label"><?= $addon->i18n('alt_checker_stat_total') ?></div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="stat-box">
                        <div class="stat-value text-success" id="stat-with-alt"><?= $stats['with_alt'] ?></div>
                        <div class="stat-label"><?= $addon->i18n('alt_checker_stat_with_alt') ?></div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="stat-box">
                        <div class="stat-value text-danger" id="stat-without-alt"><?= $stats['without_alt'] ?></div>
                        <div class="stat-label"><?= $addon->i18n('alt_checker_stat_without_alt') ?></div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="stat-box">
                        <?php
                        $percentClass = 'progress-bar-danger';
                        if ($stats['percent_complete'] >= 90) $percentClass = 'progress-bar-success';
                        elseif ($stats['percent_complete'] >= 50) $percentClass = 'progress-bar-warning';
                        ?>
                        <div class="progress" style="height: 30px; margin: 5px 0;">
                            <div id="progress-bar" class="progress-bar <?= $percentClass ?>" role="progressbar" style="width: <?= $stats['percent_complete'] ?>%; line-height: 30px;">
                                <?= $stats['percent_complete'] ?>%
                            </div>
                        </div>
                        <div class="stat-label"><?= $addon->i18n('alt_checker_stat_complete') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter -->
    <?php if (!$aiEnabled): ?>
    <div class="alert alert-info alert-dismissible" style="margin-bottom: 15px; padding: 10px 15px;">
        <button type="button" class="close" data-dismiss="alert" style="right: 10px;">&times;</button>
        <?= $magicIconHtml ?>
        <?= $addon->i18n('alt_checker_ai_hint') ?>
    </div>
    <?php endif; ?>
    
    <form method="get" action="<?= rex_url::currentBackendPage() ?>" class="form-inline" style="margin-bottom: 15px;">
        <input type="hidden" name="page" value="mediapool/alt_checker">
        
        <div class="form-group">
            <label for="filter_filename" class="sr-only"><?= $addon->i18n('alt_checker_filename') ?></label>
            <input type="text" class="form-control" id="filter_filename" name="filter_filename" 
                   value="<?= rex_escape($filterFilename) ?>"
                   placeholder="<?= $addon->i18n('alt_checker_filter_filename') ?>" style="width: 200px;">
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label for="filter_category" class="sr-only"><?= $addon->i18n('alt_checker_category') ?></label>
            <?php echo $selMediaFilter->get(); ?>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-left: 10px;">
            <i class="fa fa-search"></i> <?= $addon->i18n('alt_checker_search') ?>
        </button>
        <a href="<?= rex_url::currentBackendPage() ?>" class="btn btn-default" style="margin-left: 5px;">
            <i class="fa fa-refresh"></i>
        </a>
    </form>

    <!-- Bilder-Tabelle -->
    <div class="panel panel-default">
        <div class="panel-heading">
            <div class="panel-title">
                <i class="fa fa-image fa-fw"></i> 
                <?= $addon->i18n('alt_checker_images_without_alt') ?>
                <span id="image-count" class="badge"><?= $totalCount ?></span>
                
                <div class="pull-right">
                    <?php if (AltTextGenerator::isEnabled() && count($images) > 0): ?>
                    <button type="button" class="btn btn-info btn-xs" id="btn-ai-generate-all">
                        <?= $magicIconHtml ?><?= $addon->i18n('alt_checker_ai_generate_all') ?>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success btn-xs" id="btn-save-all" disabled>
                        <i class="fa fa-save"></i> <?= $addon->i18n('alt_checker_save_all') ?>
                    </button>
                </div>
            </div>
        </div>
        
        <?php if (count($images) > 0): ?>
        <?php if ($aiEnabled && [] !== $blockedLabelParts): ?>
        <div class="alert alert-info" style="margin: 12px 12px 0 12px;">
            <i class="fa fa-info-circle"></i>
            <?= $addon->i18n('alt_checker_blocked_languages', implode(', ', $blockedLabelParts), $fallbackName . ' (' . strtoupper($fallbackLangCode) . ')') ?>
        </div>
        <?php endif; ?>
        <div class="panel-body" id="images-container" style="padding: 0;">
            <table class="table table-hover" id="images-table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th width="72"></th>
                        <th><?= $addon->i18n('alt_checker_alt_text') ?></th>
                        <th width="92"><?= $addon->i18n('alt_checker_category') ?></th>
                        <th width="132"><?= $addon->i18n('alt_checker_actions') ?></th>
                    </tr>
                </thead>
                <tbody id="images-tbody">
                    <?php 
                    $langArray = array_values($languages);
                    $firstLang = $langArray[0] ?? ['id' => $currentLangId, 'code' => 'DE', 'name' => 'Deutsch'];
                    $otherLangs = array_slice($langArray, 1);
                    $hasMoreLangs = $isMultiLang && count($otherLangs) > 0;
                    
                    foreach ($images as $index => $img): 
                        $rawFilename = $img['filename'] ?? '';
                        $imgFilename = is_string($rawFilename) ? $rawFilename : '';
                        $rawTitle = $img['title'] ?? '';
                        $imgTitle = is_string($rawTitle) ? $rawTitle : '';
                        $rawWidth = $img['width'] ?? '?';
                        $imgWidth = is_scalar($rawWidth) ? (string) $rawWidth : '?';
                        $rawHeight = $img['height'] ?? '?';
                        $imgHeight = is_scalar($rawHeight) ? (string) $rawHeight : '?';
                        $rawCategoryId = $img['category_id'] ?? 0;
                        $imgCategoryId = is_numeric($rawCategoryId) ? (int) $rawCategoryId : 0;
                        $categoryName = 0 === $imgCategoryId 
                            ? rex_i18n::msg('pool_kats_no') 
                            : (rex_media_category::get($imgCategoryId)?->getName() ?? '-');
                        
                        $isSvg = 'svg' === strtolower(pathinfo($imgFilename, PATHINFO_EXTENSION));
                        $thumbSrc = $isSvg 
                            ? '../media/' . urlencode($imgFilename)
                            : 'index.php?rex_media_type=rex_media_small&rex_media_file=' . urlencode($imgFilename);
                        $previewSrc = $isSvg 
                            ? '../media/' . urlencode($imgFilename)
                            : 'index.php?rex_media_type=rex_media_medium&rex_media_file=' . urlencode($imgFilename);
                    ?>
                    <tr data-filename="<?= rex_escape($imgFilename) ?>" class="image-row">
                        <td>
                            <span class="preview-toggle" data-filename="<?= rex_escape($imgFilename) ?>" title="<?= $addon->i18n('alt_checker_show_preview') ?>">
                                <i class="fa fa-chevron-right"></i>
                                <img src="<?= $thumbSrc ?>" alt="" class="thumb-mini" loading="lazy">
                            </span>
                        </td>
                        <td class="alt-main-cell">
                            <div class="alt-entry-meta">
                                <strong class="filename-main" title="<?= rex_escape($imgFilename) ?>"><?= rex_escape($imgFilename) ?></strong>
                                <small class="filename-meta" title="<?= rex_escape($imgTitle) ?>">
                                    <?php if ('' !== $imgTitle): ?>
                                        <?= rex_escape($imgTitle) ?> ·
                                    <?php endif; ?>
                                    <?= $imgWidth ?> × <?= $imgHeight ?> px
                                </small>
                            </div>
                            <?php if ($isMultiLang && $hasMoreLangs): ?>
                                <div class="alt-input-group">
                                    <span class="lang-badge"><?= strtoupper((string) $firstLang['code']) ?></span>
                                    <input type="text" class="form-control input-sm alt-input" 
                                           data-filename="<?= rex_escape($imgFilename) ?>"
                                           data-clang-id="<?= (int) $firstLang['id'] ?>"
                                           value=""
                                           placeholder="<?= $addon->i18n('alt_checker_enter_alt') ?>"
                                           tabindex="<?= $index + 1 ?>">
                                    <button type="button" class="btn btn-default btn-sm lang-toggle" 
                                            data-filename="<?= rex_escape($imgFilename) ?>" 
                                            title="<?= $addon->i18n('alt_checker_more_languages') ?> (<?= count($otherLangs) ?>)">
                                        <i class="fa fa-globe"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <input type="text" class="form-control input-sm alt-input" 
                                       data-filename="<?= rex_escape($imgFilename) ?>"
                                       data-clang-id="<?= $currentLangId ?>"
                                       value=""
                                       placeholder="<?= $addon->i18n('alt_checker_enter_alt') ?>"
                                       tabindex="<?= $index + 1 ?>">
                            <?php endif; ?>
                        </td>
                        <td><small><?= rex_escape($categoryName) ?></small></td>
                        <td class="text-nowrap">
                            <?php if ($aiEnabled && !$isSvg): ?>
                            <button type="button" class="btn btn-info btn-xs btn-ai-generate" 
                                    data-filename="<?= rex_escape($imgFilename) ?>" title="<?= $addon->i18n('alt_checker_ai_generate') ?>">
                                <?= $magicIconHtml ?>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-success btn-xs btn-save-row" 
                                    data-filename="<?= rex_escape($imgFilename) ?>" title="<?= $addon->i18n('alt_checker_save') ?>">
                                <i class="fa fa-check"></i>
                            </button>
                            <button type="button" class="btn btn-default btn-xs btn-ignore" 
                                    data-filename="<?= rex_escape($imgFilename) ?>" title="<?= $addon->i18n('alt_checker_ignore_decorative') ?>">
                                <i class="fa fa-eye-slash"></i>
                            </button>
                        </td>
                    </tr>
                    
                    <?php if ($hasMoreLangs): ?>
                    <tr class="lang-row" data-filename="<?= rex_escape($imgFilename) ?>">
                        <td></td>
                        <td>
                            <div class="other-langs-container">
                                <?php foreach ($otherLangs as $lang): ?>
                                <div class="input-group input-group-sm" style="margin-bottom: 5px;">
                                    <span class="input-group-addon" style="min-width: 35px;"><?= strtoupper((string) $lang['code']) ?></span>
                                    <input type="text" class="form-control alt-input" 
                                           data-filename="<?= rex_escape($imgFilename) ?>"
                                           data-clang-id="<?= (int) $lang['id'] ?>"
                                           value=""
                                           placeholder="<?= $addon->i18n('alt_checker_enter_alt') ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr class="preview-row" data-filename="<?= rex_escape($imgFilename) ?>">
                        <td colspan="4">
                            <div class="preview-container">
                                <img src="<?= $previewSrc ?>" 
                                     alt="" loading="lazy"<?= $isSvg ? ' style="max-width: 300px; background: #f5f5f5; padding: 10px;"' : '' ?>>
                                <div style="margin-top: 10px;">
                                    <a href="index.php?page=mediapool/media&file_name=<?= urlencode($imgFilename) ?>" 
                                       target="_blank" class="btn btn-default btn-xs">
                                        <i class="fa fa-external-link"></i> <?= $addon->i18n('alt_checker_open_mediapool') ?>
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-footer">
            <?php
            $urlProvider = new rex_context([
                'page' => $currentPage,
                'filter_filename' => $filterFilename,
                'filter_category' => $filterCategory,
                'items_per_page' => $itemsPerPage
            ]);
            $fragment = new rex_fragment();
            $fragment->setVar('urlprovider', $urlProvider);
            $fragment->setVar('pager', $pager);
            echo $fragment->parse('core/navigations/pagination.php');
            ?>
        </div>
        <?php else: ?>
        <div class="panel-body">
            <?php if (!empty($filters)): ?>
                <div class="alert alert-warning"> <!-- Warning color usually better for "no results found" than info or success -->
                    <i class="fa fa-search"></i> <?= $addon->i18n('alt_checker_no_results') ?>
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    <i class="fa fa-check-circle"></i> <?= $addon->i18n('alt_checker_all_complete') ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <?php endif; ?>
</div>

<style>
.stat-box {
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 10px;
}
.stat-value {
    font-size: 24px;
    font-weight: bold;
    line-height: 1.2;
}
.stat-label {
    font-size: 11px;
    text-transform: uppercase;
    opacity: 0.7;
    margin-top: 5px;
}
#images-table {
    width: 100%;
    table-layout: fixed;
}
#images-table td {
    vertical-align: top;
}
#images-table th:nth-child(2),
#images-table td:nth-child(2) {
    width: 62%;
}
#images-table .thumb-mini {
    max-width: 52px;
    max-height: 38px;
    border-radius: 3px;
}
.preview-toggle {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
}
.preview-toggle i {
    transition: transform 0.2s;
}
.preview-toggle.open i {
    transform: rotate(90deg);
}
.preview-row {
    display: none;
}
.preview-row.open {
    display: table-row;
}
.preview-row td {
    padding: 15px 20px !important;
    background: rgba(0,0,0,0.02);
}
.preview-container {
    text-align: center;
}
.preview-container img {
    max-width: 100%;
    max-height: 400px;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
#images-table .alt-input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    transition: border-color 0.2s;
}
#images-table .alt-input:focus {
    border-color: #3bb594;
    outline: none;
    box-shadow: none;
}
#images-table .alt-input.modified {
    border-color: #f0ad4e;
    
}
#images-table .alt-input.saved {
    border-color: #3bb594;
   
}
#images-table tr.saving {
    opacity: 0.6;
}
.btn-save-row {
    opacity: 0;
    transition: opacity 0.2s;
}
tr:hover .btn-save-row,
.btn-save-row.visible {
    opacity: 1;
}
.btn-ignore {
    margin-left: 3px;
}
#images-table tr.ignored {
    opacity: 0.5;
}
#images-table .alt-input.decorative {
    background: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 5px,
        rgba(0,0,0,0.03) 5px,
        rgba(0,0,0,0.03) 10px
    );
    color: #999;
    font-style: italic;
}
.alt-main-cell {
    overflow: hidden;
}
.alt-entry-meta {
    margin-bottom: 6px;
}
.filename-main {
    display: block;
    font-weight: 400;
    font-size: 12px;
    color: #6f7780;
    white-space: nowrap;
    text-overflow: ellipsis;
    overflow: hidden;
    margin-bottom: 2px;
}
.filename-meta {
    display: block;
    color: #6f7780;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
/* Mehrsprachige Alt-Text Felder */
.alt-input-group {
    display: flex;
    align-items: stretch;
    gap: 6px;
    width: 100%;
}
.alt-input-group .alt-input {
    flex: 1;
    min-width: 320px;
}
.alt-input-group .lang-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 4px 6px;
    border-radius: 3px;
    background: rgba(128,128,128,0.15);
    color: inherit;
    min-width: 28px;
    text-align: center;
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
}
.alt-input-group .lang-toggle {
    padding: 6px 8px;
    border-radius: 4px;
    opacity: 0.6;
    transition: opacity 0.2s, background 0.2s;
    flex: 0 0 auto;
}
.alt-input-group .lang-toggle:hover {
    opacity: 1;
}
.alt-input-group .lang-toggle.active {
    opacity: 1;
    background-color: #337ab7;
    color: #fff;
}
.lang-row {
    display: none;
}
.lang-row.open {
    display: table-row;
}
.lang-row td {
    padding-top: 0 !important;
    border-top: none !important;
    background: rgba(0,0,0,0.02);
}
.other-langs-container {
    padding: 8px 0 5px 0;
}
.other-langs-container .input-group {
    margin-bottom: 6px;
}
.other-langs-container .input-group:last-child {
    margin-bottom: 0;
}
.other-langs-container .input-group-addon {
    min-width: 32px;
    font-size: 10px;
    font-weight: 600;
}

.fp-spinner {
    display: inline-block;
    width: 12px;
    height: 12px;
    margin-right: 6px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    vertical-align: -2px;
    animation: fpSpin 0.7s linear infinite;
}

@keyframes fpSpin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}
</style>

<script nonce="<?= rex_response::getNonce() ?>">
$(document).on('rex:ready', function() {
    const AltChecker = {
        apiEndpoint: <?= json_encode($apiEndpoint) ?>,
        isMultiLang: <?= json_encode($isMultiLang) ?>,
        languages: <?= json_encode($languages) ?>,
        currentLangId: <?= json_encode($currentLangId) ?>,
        aiEnabled: <?= json_encode(AltTextGenerator::isEnabled()) ?>,
        spinnerMarkup: '<span class="fp-spinner" aria-hidden="true"></span>',
        L: <?= json_encode([
            'error' => rex_i18n::rawMsg('filepond_error'),
            'unknown' => rex_i18n::rawMsg('filepond_error_unknown'),
            'saving' => rex_i18n::rawMsg('filepond_saving'),
            'saveFailed' => rex_i18n::rawMsg('alt_checker_save_failed'),
            'aiError' => rex_i18n::rawMsg('alt_checker_ai_error'),
            'skipped' => rex_i18n::rawMsg('alt_checker_ai_skipped'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        modifiedImages: new Set(),
        
        init() {
            this.bindEvents();
        },
        
        bindEvents() {
            $('#btn-save-all').on('click', () => this.saveAll());
            
            // Inline-Edit Events
            $(document).on('input', '.alt-input', (e) => {
                const $input = $(e.target);
                const filename = $input.data('filename');
                $input.addClass('modified').removeClass('saved');
                $input.closest('tr.image-row').find('.btn-save-row').addClass('visible');
                this.modifiedImages.add(filename);
                this.updateSaveAllButton();
            });
            
            $(document).on('keydown', '.alt-input', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const filename = $(e.target).data('filename');
                    this.saveOne(filename);
                }
                // Tab to next input
                if (e.key === 'Tab' && !e.shiftKey) {
                    const $inputs = $('.alt-input');
                    const currentIndex = $inputs.index(e.target);
                    if (currentIndex < $inputs.length - 1) {
                        e.preventDefault();
                        $inputs.eq(currentIndex + 1).focus();
                    }
                }
            });
            
            $(document).on('click', '.btn-save-row', (e) => {
                const filename = $(e.currentTarget).data('filename');
                this.saveOne(filename);
            });
            
            // Akkordeon-Vorschau togglen
            $(document).on('click', '.preview-toggle', (e) => {
                const $toggle = $(e.currentTarget);
                const filename = $toggle.data('filename');
                const $previewRow = $(`.preview-row[data-filename="${CSS.escape(filename)}"]`);
                
                $toggle.toggleClass('open');
                $previewRow.toggleClass('open');
            });
            
            // Sprachen-Toggle (Globus-Button)
            $(document).on('click', '.lang-toggle', (e) => {
                e.stopPropagation();
                const $toggle = $(e.currentTarget);
                const filename = $toggle.data('filename');
                const $langRow = $(`.lang-row[data-filename="${CSS.escape(filename)}"]`);
                
                $toggle.toggleClass('active');
                $langRow.toggleClass('open');
            });
            
            // Ignorieren (dekoratives Bild)
            $(document).on('click', '.btn-ignore', (e) => {
                const filename = $(e.currentTarget).data('filename');
                this.ignoreImage(filename);
            });
            
            // AI: Einzelnes Bild generieren
            $(document).on('click', '.btn-ai-generate', (e) => {
                const filename = $(e.currentTarget).data('filename');
                this.aiGenerateSingle(filename);
            });
            
            // AI: Alle generieren
            $('#btn-ai-generate-all').on('click', () => this.aiGenerateAll());
        },
        
        updateSaveAllButton() {
            $('#btn-save-all').prop('disabled', this.modifiedImages.size === 0);
        },
        
        saveOne(filename) {
            const $row = $(`tr.image-row[data-filename="${CSS.escape(filename)}"]`);
            const $langRow = $(`.lang-row[data-filename="${CSS.escape(filename)}"]`);
            const $previewRow = $(`.preview-row[data-filename="${CSS.escape(filename)}"]`);
            
            // Alle Inputs sammeln (aus Hauptzeile und Sprach-Zeile)
            const $allInputs = $row.find('.alt-input').add($langRow.find('.alt-input'));
            
            // Alt-Text sammeln (einsprachig oder mehrsprachig)
            let altData = {};
            let hasValue = false;
            
            if (this.isMultiLang) {
                // Mehrsprachig: alle Sprachen sammeln
                $allInputs.each((i, input) => {
                    const $input = $(input);
                    const clangId = $input.data('clang-id');
                    const value = $input.val().trim();
                    altData[clangId] = value;
                    if (value) hasValue = true;
                });
            } else {
                // Einsprachig
                const altText = $allInputs.first().val().trim();
                if (altText) {
                    altData = altText;
                    hasValue = true;
                }
            }
            
            if (!hasValue) {
                $allInputs.first().focus();
                return;
            }
            
            $row.addClass('saving');
            
            $.post(this.apiEndpoint, {
                action: 'update',
                filename: filename,
                alt_text: typeof altData === 'object' ? JSON.stringify(altData) : altData,
                is_multilang: this.isMultiLang && typeof altData === 'object'
            })
            .done((response) => {
                if (response.success) {
                    $allInputs.removeClass('modified').addClass('saved');
                    $row.find('.btn-save-row').removeClass('visible');
                    this.modifiedImages.delete(filename);
                    this.updateSaveAllButton();
                    
                    // Nach kurzer Zeit aus Tabelle entfernen (inkl. Sprach-Zeile)
                    setTimeout(() => {
                        $row.fadeOut(300);
                        $langRow.fadeOut(300);
                        $previewRow.fadeOut(300, () => {
                            $row.remove();
                            $langRow.remove();
                            $previewRow.remove();
                            
                            // Stats neu laden (optional, oder einfach Seite neu laden)
                            // this.loadStats();
                        });
                    }, 500);
                } else {
                    alert(this.L.error + ': ' + (response.error || this.L.unknown));
                }
            })
            .fail((xhr, status, error) => {
                alert(this.L.error + ': ' + error);
            })
            .always(() => {
                $row.removeClass('saving');
            });
        },
        
        saveAll() {
            const updates = [];
            
            this.modifiedImages.forEach(filename => {
                // Finde alle Inputs für diese Datei
                const $allInputs = $(`.alt-input`).filter(function() {
                    return $(this).data('filename') === filename;
                });
                
                // Prüfe ob mehrsprachig (mehrere Inputs pro Datei)
                if ($allInputs.length > 1) {
                    // Mehrsprachig: Sammle alle Sprachen
                    const langTexts = {};
                    $allInputs.each(function() {
                        // jQuery konvertiert data-clang-id zu clangId (camelCase)
                        const clangId = $(this).data('clangId');
                        const text = $(this).val().trim();
                        // Speichere auch leere Texte, damit die Sprache aktualisiert wird
                        if (clangId !== undefined) {
                            langTexts[clangId] = text;
                        }
                    });
                    if (Object.keys(langTexts).length > 0) {
                        updates.push({ filename, lang_texts: langTexts });
                    }
                } else if ($allInputs.length === 1) {
                    // Einsprachig
                    const altText = $allInputs.val().trim();
                    updates.push({ filename, alt_text: altText });
                }
            });
            
            if (updates.length === 0) {
                return;
            }
            
            $('#btn-save-all').prop('disabled', true).html(this.spinnerMarkup + this.escapeHtml(this.L.saving));
            
            $.post(this.apiEndpoint, {
                action: 'bulk_update',
                updates: JSON.stringify(updates)
            })
            .done((response) => {
                if (response.success > 0) {
                    // Seite neu laden um Liste zu aktualisieren
                    window.location.reload();
                }
                if (response.failed > 0) {
                    alert(this.L.saveFailed.replace('{0}', response.failed));
                }
            })
            .fail((xhr, status, error) => {
                alert(this.L.error + ': ' + error);
            })
            .always(() => {
                $('#btn-save-all').prop('disabled', false).html('<i class="fa fa-save"></i> <?= $addon->i18n('alt_checker_save_all') ?>');
            });
        },
        
        ignoreImage(filename) {
            const $row = $(`tr.image-row[data-filename="${CSS.escape(filename)}"]`);
            const $previewRow = $(`.preview-row[data-filename="${CSS.escape(filename)}"]`);
            const $input = $row.find('.alt-input');
            
            $row.addClass('saving');
            
            // Dekoratives Bild = leerer alt-Text mit speziellem Marker
            $.post(this.apiEndpoint, {
                action: 'update',
                filename: filename,
                alt_text: '',
                decorative: true
            })
            .done((response) => {
                if (response.success) {
                    $input.val('<?= $addon->i18n('alt_checker_decorative') ?>').addClass('decorative').prop('disabled', true);
                    $row.addClass('ignored');
                    $row.find('.btn-save-row, .btn-ignore, .btn-ai-generate').hide();
                    
                    // Nach kurzer Zeit aus Tabelle entfernen
                    setTimeout(() => {
                        $row.fadeOut(300);
                        $previewRow.fadeOut(300, () => {
                            $row.remove();
                            $previewRow.remove();
                        });
                    }, 800);
                } else {
                    alert(this.L.error + ': ' + (response.error || this.L.unknown));
                }
            })
            .fail((xhr, status, error) => {
                alert(this.L.error + ': ' + error);
            })
            .always(() => {
                $row.removeClass('saving');
            });
        },

        normalizeLanguageCode(code) {
            if (typeof code !== 'string') {
                return '';
            }

            const normalized = code.trim().toLowerCase().slice(0, 2);
            return /^[a-z]{2}$/.test(normalized) ? normalized : '';
        },

        getRequestedLanguagesForInputs($inputs) {
            const languageCodes = [];

            $inputs.each((_, input) => {
                const $input = $(input);
                const clangId = parseInt($input.data('clangId'), 10);
                const languageConfig = this.languages[clangId] || null;
                const languageCode = this.normalizeLanguageCode(languageConfig && languageConfig.code ? languageConfig.code : '');

                if (languageCode && !languageCodes.includes(languageCode)) {
                    languageCodes.push(languageCode);
                }
            });

            if (languageCodes.length === 0) {
                const currentLanguage = this.languages[this.currentLangId] || null;
                const fallbackLang = this.normalizeLanguageCode(currentLanguage && currentLanguage.code ? currentLanguage.code : 'de');
                if (fallbackLang) {
                    languageCodes.push(fallbackLang);
                }
            }

            return languageCodes;
        },

        async requestAiBatch(filename, languageCodes) {
            return $.getJSON(this.apiEndpoint, {
                action: 'ai_generate',
                filename: filename,
                languages: languageCodes
            });
        },

        applyAiTextsToInputs($inputs, altTexts) {
            let updated = false;

            $inputs.each((_, input) => {
                const $input = $(input);
                const clangId = parseInt($input.data('clangId'), 10);
                const languageConfig = this.languages[clangId] || null;
                const languageCode = this.normalizeLanguageCode(languageConfig && languageConfig.code ? languageConfig.code : '');

                if (!languageCode) {
                    return;
                }

                const generatedText = (altTexts && typeof altTexts === 'object') ? (altTexts[languageCode] || '') : '';
                if (typeof generatedText === 'string' && generatedText.trim() !== '') {
                    $input.val(generatedText.trim()).addClass('modified');
                    updated = true;
                }
            });

            return updated;
        },

        showSkippedLanguageInfo(skippedLanguages, fallbackLanguage) {
            if (!Array.isArray(skippedLanguages) || skippedLanguages.length === 0 || !fallbackLanguage) {
                return;
            }

            const fallbackCode = this.normalizeLanguageCode(String(fallbackLanguage));
            if (!fallbackCode) {
                return;
            }

            const languageNamesByCode = {};
            Object.values(this.languages || {}).forEach((lang) => {
                if (!lang || typeof lang !== 'object') {
                    return;
                }

                const code = this.normalizeLanguageCode(String(lang.code || ''));
                if (!code || languageNamesByCode[code]) {
                    return;
                }

                languageNamesByCode[code] = String(lang.name || code.toUpperCase());
            });

            const skippedUnique = [];
            skippedLanguages.forEach((entry) => {
                const code = this.normalizeLanguageCode(String(entry));
                if (code && !skippedUnique.includes(code)) {
                    skippedUnique.push(code);
                }
            });

            if (skippedUnique.length === 0) {
                return;
            }

            const skippedLabel = skippedUnique
                .map((code) => `${languageNamesByCode[code] || code.toUpperCase()} (${code.toUpperCase()})`)
                .join(', ');
            const fallbackLabel = `${languageNamesByCode[fallbackCode] || fallbackCode.toUpperCase()} (${fallbackCode.toUpperCase()})`;

            const $container = $('#images-container');
            if ($container.length === 0) {
                return;
            }

            const infoHtml = `
                <div id="alt-checker-ai-skip-info" class="alert alert-info" style="margin: 12px; margin-bottom: 0;">
                    <i class="fa fa-info-circle"></i>
                    ${this.escapeHtml(this.L.skipped.replace('{0}', skippedLabel).replace('{1}', fallbackLabel))}
                </div>
            `;

            $('#alt-checker-ai-skip-info').remove();
            $container.before(infoHtml);
        },
        
        // AI: Alt-Text für einzelnes Bild generieren (alle Sprachen bei multilang)
        async aiGenerateSingle(filename) {
            const $row = $(`tr.image-row[data-filename="${CSS.escape(filename)}"]`);
            const $langRow = $(`.lang-row[data-filename="${CSS.escape(filename)}"]`);
            const $btn = $row.find('.btn-ai-generate');
            const $allInputs = $row.find('.alt-input').add($langRow.find('.alt-input'));
            const languageCodes = this.getRequestedLanguagesForInputs($allInputs);

            if (languageCodes.length === 0) {
                return;
            }
            
            // Button-Status ändern
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html(this.spinnerMarkup);
            $row.addClass('saving');

            try {
                const response = await this.requestAiBatch(filename, languageCodes);

                if (!response.success) {
                    alert(this.L.aiError + ': ' + (response.error || this.L.unknown));
                    return;
                }

                let updated = false;
                if (response.alt_texts && typeof response.alt_texts === 'object') {
                    updated = this.applyAiTextsToInputs($allInputs, response.alt_texts);
                } else if (typeof response.alt_text === 'string' && response.alt_text.trim() !== '') {
                    $allInputs.first().val(response.alt_text.trim()).addClass('modified').focus();
                    updated = true;
                }

                if (updated) {
                    this.modifiedImages.add(filename);
                    $row.find('.btn-save-row').addClass('visible');
                    this.updateSaveAllButton();

                    if (this.isMultiLang && $allInputs.length > 1) {
                        $row.find('.lang-toggle').addClass('active');
                        $langRow.addClass('open');
                    }
                }

                if (response.blocked_languages_used && response.fallback_language) {
                    this.showSkippedLanguageInfo(response.blocked_languages_used, response.fallback_language);
                }
            } catch (e) {
                alert(this.L.aiError + ': ' + e.message);
            } finally {
                $btn.prop('disabled', false).html(originalHtml);
                $row.removeClass('saving');
            }
        },
        
        // AI: Alt-Texte für alle Bilder generieren
        async aiGenerateAll() {
            const $btn = $('#btn-ai-generate-all');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true);
            
            // Alle sichtbaren Bilder sammeln
            const visibleImages = [];
            $('.image-row').each(function() {
                visibleImages.push($(this).data('filename'));
            });
            
            if (visibleImages.length === 0) return;
            
            let processed = 0;
            const total = visibleImages.length;
            
            for (const filename of visibleImages) {
                processed++;
                $btn.html(`${this.spinnerMarkup} ${processed}/${total}`);
                
                const $row = $(`tr.image-row[data-filename="${CSS.escape(filename)}"]`);
                const $langRow = $(`.lang-row[data-filename="${CSS.escape(filename)}"]`);
                // Alle Inputs: aus der Hauptzeile UND der Sprachzeile
                const $allInputs = $row.find('.alt-input').add($langRow.find('.alt-input'));

                const languageCodes = this.getRequestedLanguagesForInputs($allInputs);
                if (languageCodes.length === 0) {
                    continue;
                }

                try {
                    const response = await this.requestAiBatch(filename, languageCodes);

                    if (!response.success) {
                        continue;
                    }

                    let updated = false;
                    if (response.alt_texts && typeof response.alt_texts === 'object') {
                        updated = this.applyAiTextsToInputs($allInputs, response.alt_texts);
                    } else if (typeof response.alt_text === 'string' && response.alt_text.trim() !== '') {
                        $allInputs.first().val(response.alt_text.trim()).addClass('modified');
                        updated = true;
                    }

                    if (updated) {
                        this.modifiedImages.add(filename);
                        $row.find('.btn-save-row').addClass('visible');
                    }

                    if (response.blocked_languages_used && response.fallback_language) {
                        this.showSkippedLanguageInfo(response.blocked_languages_used, response.fallback_language);
                    }
                } catch (e) {
                    console.error('AI error for ' + filename, e);
                }
            }
            
            this.updateSaveAllButton();
            $btn.prop('disabled', false).html(originalHtml);
        },
        
        escapeHtml(text) {
            return String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        },
    };
    
    AltChecker.init();
});
</script>
