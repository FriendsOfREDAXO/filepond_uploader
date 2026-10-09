<?php

/** @var rex_addon $this */

// API-Token für externe Clients und als Schlüssel der Kategorie-Signaturen
if (!is_string($this->getConfig('api_token')) || '' === $this->getConfig('api_token')) {
    $this->setConfig('api_token', bin2hex(random_bytes(32)));
}

// Upload-Verzeichnisse
foreach (['upload/chunks', 'upload/metadata'] as $dir) {
    rex_dir::create($this->getDataPath($dir));
}

// Metainfo-Felder für Alt-Text und Copyright (legt auch die Spalten in rex_media an)
$fields = [
    'med_alt' => ['translate:filepond_metainfo_alt', 2],
    'med_copyright' => ['translate:filepond_metainfo_copyright', 3],
];
$sql = rex_sql::factory();
foreach ($fields as $name => [$title, $priority]) {
    $sql->setQuery('SELECT 1 FROM ' . rex::getTable('metainfo_field') . ' WHERE name = ?', [$name]);
    if ($sql->getRows() > 0) {
        continue;
    }

    $result = rex_metainfo_add_field($title, $name, $priority, '', 1, '');
    if (true !== $result) {
        $this->setProperty('installmsg', is_string($result) ? $result : 'Metainfo field ' . $name . ' could not be created');

        return false;
    }
}

return true;
