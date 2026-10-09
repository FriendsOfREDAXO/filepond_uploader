<?php

declare(strict_types=1);
use FriendsOfRedaxo\FilePondUploader\Helper;

$addon = rex_addon::get('filepond_uploader');

if (!$addon->isAvailable()) {
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><p>Das AddOn filepond_uploader ist nicht installiert oder nicht aktiviert.</p></body></html>';
    return;
}

$boolConfig = static function (string $key, bool $default): bool {
    $raw = rex_config::get('filepond_uploader', $key, $default ? '1' : '0');
    return in_array($raw, [1, '1', true, 'true', '|1|'], true);
};

$langCode = rex_clang::getCurrentId() === 1 ? 'de_de' : 'en_gb';

$allowedTypes = (string) rex_config::get('filepond_uploader', 'allowed_types', 'image/*');
$maxFiles = (string) (int) rex_config::get('filepond_uploader', 'max_files', 5);
$maxFilesize = (string) (int) rex_config::get('filepond_uploader', 'max_filesize', 10);
$categoryId = (string) (int) rex_config::get('filepond_uploader', 'category_id', 0);

$titleRequired = $boolConfig('title_required_default', false);
$altRequired = $boolConfig('alt_required_default', true);
$aiEnabled = $boolConfig('enable_ai_alt', false) && $boolConfig('enable_ai_upload_modal', true);

$aiTargetFieldRaw = (string) rex_config::get('filepond_uploader', 'ai_target_field', 'med_alt');
$aiTargetField = '' !== trim($aiTargetFieldRaw) ? trim($aiTargetFieldRaw) : 'med_alt';

$apiTokenConfig = rex_config::get('filepond_uploader', 'api_token');
$apiToken = is_string($apiTokenConfig) ? trim($apiTokenConfig) : '';

// Für Frontend-Demos ohne Login den konfigurierten Token in die Session spiegeln,
// damit api_filepond::isAuthorized() greift.
if ('' !== $apiToken) {
    rex_set_session('filepond_token', $apiToken);
}

$isBackendUser = null !== rex_backend_login::createUser();
$isYComUser = false;
if (rex_plugin::get('ycom', 'auth')->isAvailable()) {
    /** @phpstan-ignore class.notFound */
    $isYComUser = null !== rex_ycom_auth::getUser();
}

$demoHasUploadAuth = $isBackendUser || $isYComUser || '' !== $apiToken;

$styles = Helper::getStyles();
$scripts = Helper::getScripts();


?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FilePond Frontend-Demo</title>
    <?= $styles ?>
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #222;
            background: #fff;
        }

        .container {
            max-width: 900px;
            margin: 40px auto;
            padding: 0 15px;
        }

        .alert {
            padding: 12px;
            border-radius: 4px;
        }

        .alert-warning {
            border: 1px solid #f0c36d;
            background: #fff9e8;
            color: #6a531a;
        }

        .btn {
            border: 1px solid transparent;
            border-radius: 4px;
            padding: 8px 14px;
            cursor: pointer;
        }

        .btn-primary {
            background: #3b82f6;
            border-color: #3b82f6;
            color: #fff;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>FilePond Frontend-Demo</h1>
    <p>
        Diese Seite ist eine echte Frontend-Referenz für das AddOn.
        Sie lädt alle benötigten Assets automatisch und nutzt dieselben data-Attribute wie das YForm-Template.
    </p>

    <?php if (!$demoHasUploadAuth): ?>
        <div class="alert alert-warning" style="margin-bottom: 15px;">
            Upload-Autorisierung fehlt: Bitte YCom-Login verwenden oder in den AddOn-Einstellungen einen API-Token setzen.
        </div>
    <?php endif; ?>

    <div class="form-group filepond-demo-uploader" id="demo-filepond-wrapper">
        <label class="control-label" for="demo-filepond-upload">Upload</label>
        <input
            id="demo-filepond-upload"
            type="hidden"
            name="demo_filepond_files"
            value=""
            data-widget="filepond"
            data-filepond-cat="<?= rex_escape($categoryId) ?>"
            data-filepond-maxfiles="<?= rex_escape($maxFiles) ?>"
            data-filepond-types="<?= rex_escape($allowedTypes) ?>"
            data-filepond-maxsize="<?= rex_escape($maxFilesize) ?>"
            data-filepond-lang="<?= rex_escape($langCode) ?>"
            data-filepond-skip-meta="false"
            data-filepond-chunk-enabled="false"
            data-filepond-chunk-size="1048576"
            data-filepond-delayed-upload="true"
            data-filepond-delayed-type="1"
            data-filepond-title-required="<?= $titleRequired ? 'true' : 'false' ?>"
            data-filepond-alt-required="<?= $altRequired ? 'true' : 'false' ?>"
            data-filepond-max-pixel="2100"
            data-filepond-image-quality="90"
            data-filepond-client-resize="false"
            data-filepond-ai-enabled="<?= $aiEnabled ? 'true' : 'false' ?>"
            data-filepond-ai-target-field="<?= rex_escape($aiTargetField) ?>"
        >
        <p class="help-block">Verzögerter Upload ist aktiv. Dateien werden erst über den FilePond-Upload-Button hochgeladen.</p>
    </div>

    <hr>
    <h3>Einbau in dein Projekt</h3>
    <ol>
        <li>Datei in dein Projekt kopieren, z. B. als Template- oder Modul-Output.</li>
        <li>Wichtig: <code>echo Helper::getStyles();</code> und <code>echo Helper::getScripts();</code> müssen vorhanden sein.</li>
        <li>Hidden-Input mit <code>data-widget="filepond"</code> verwenden.</li>
        <li>API-Aufrufe laufen über <code>/redaxo/index.php?rex-api-call=...</code> und benötigen ein laufendes REDAXO.</li>
    </ol>
</div>

<?= $scripts ?>
</body>
</html>