<?php

$addon = rex_addon::get('filepond_uploader');

// 3.0: KI läuft über ai_platform, eigene Provider-, Modell- und Schlüssel-Einstellungen entfallen.
foreach ([
    'ai_provider',
    'ai_max_tokens',
    'gemini_api_key',
    'gemini_model',
    'cloudflare_api_token',
    'cloudflare_account_id',
    'cloudflare_model',
    'openwebui_api_key',
    'openwebui_base_url',
    'openwebui_model',
] as $key) {
    $addon->removeConfig($key);
}

// Zwischenspeicher der früheren Gemini-Modellliste
rex_file::delete(rex_path::addonCache('filepond_uploader', 'gemini_models.json'));
