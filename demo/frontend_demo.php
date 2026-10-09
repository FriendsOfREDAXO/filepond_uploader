<?php

declare(strict_types=1);

use FriendsOfRedaxo\FilePondUploader\Config;
use FriendsOfRedaxo\FilePondUploader\Helper;

/*
 * Frontend-Referenz: Upload für Gäste über den API-Token in der Session, für YCom-User über
 * deren Login. Kategorie und Feldgrenzen werden signiert, der Server prüft sie nach.
 */

$addon = rex_addon::get('filepond_uploader');
if (!$addon->isAvailable()) {
    echo '<p>Das AddOn filepond_uploader ist nicht installiert oder nicht aktiviert.</p>';
    return;
}

rex_login::startSession();

// Gäste ohne YCom-Login: API-Token in die Session spiegeln (nie im HTML ausgeben)
$apiToken = Config::string('api_token');
if ('' !== $apiToken) {
    rex_set_session('filepond_token', $apiToken);
}

$categoryId = Config::int('category_id', 0);
$allowedTypes = 'image/*,application/pdf';
$maxFilesize = 10;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FilePond Frontend-Demo</title>
    <?= Helper::getStyles() ?>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; color: #222; background: #fff; }
        .container { max-width: 900px; margin: 40px auto; padding: 0 16px; }
    </style>
</head>
<body>
<div class="container">
    <h1>FilePond Frontend-Demo</h1>

    <form method="post">
        <label for="demo-filepond-upload">Upload</label>
        <input
            id="demo-filepond-upload"
            type="hidden"
            name="demo_filepond_files"
            value=""
            data-widget="filepond"
            data-filepond-cat="<?= $categoryId ?>"
            <?= Helper::configAttributes([
                'types' => $allowedTypes,
                'maxsize' => $maxFilesize,
                'lang' => 1 === rex_clang::getCurrentId() ? 'de_de' : 'en_gb',
                'delayed-upload' => true,
            ]) ?>
            <?= Helper::widgetSecurityAttributes($categoryId, $allowedTypes, $maxFilesize) ?>
        >
    </form>
</div>

<?= Helper::getScripts() ?>
</body>
</html>
