<?php

/** @var rex_addon $this */

use FriendsOfRedaxo\FilePondUploader\Api\Upload;

$addon = rex_addon::get('filepond_uploader');
$form = rex_config_form::factory('filepond_uploader');

// Aufräumen temporärer Dateien (POST mit CSRF-Token, nur Admins)
$cleanupToken = rex_csrf_token::factory('filepond_cleanup_temp');
if (rex_post('cleanup_temp', 'boolean') && rex::requireUser()->isAdmin()) {
    rex_response::cleanOutputBuffers();
    if (!$cleanupToken->isValid()) {
        rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
        rex_response::sendJson(['message' => rex_i18n::msg('csrf_token_invalid')]);
        exit;
    }
    try {
        rex_response::sendJson((new Upload())->handleCleanup());
    } catch (Throwable $e) {
        rex_logger::logException($e);
        rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
        rex_response::sendJson(['message' => $addon->i18n('filepond_maintenance_cleanup_error')]);
    }
    exit;
}

// Token neu erzeugen: nur zusammen mit gültigem Formular-Token
if (rex_post('regenerate_token', 'boolean') && rex_csrf_token::factory('filepond_regenerate_token')->isValid()) {
    $token = bin2hex(random_bytes(32));
    rex_config::set('filepond_uploader', 'api_token', $token);
    echo rex_view::success($addon->i18n('filepond_token_regenerated') . '<br><br>'
        . '<div class="input-group"><input type="text" class="form-control" id="new-token" value="' . rex_escape($token) . '" readonly>'
        . '<span class="input-group-btn"><clipboard-copy for="new-token" class="btn btn-default"><i class="fa fa-clipboard"></i> '
        . $addon->i18n('filepond_copy_token') . '</clipboard-copy></span></div>');
}

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
                        <input type="checkbox" name="regenerate_token" value="1">' . rex_csrf_token::factory('filepond_regenerate_token')->getHiddenField() . '
                        ' . $addon->i18n('filepond_regenerate_token') . '
                    </label>
                    <p class="help-block rex-warning">' . $addon->i18n('filepond_regenerate_token_warning') . '</p>
                </div>
            </div>
        </div>
    </div>
');

$form->addFieldset($addon->i18n('filepond_settings_debug'));
// Debug-Logging aktivieren
$field = $form->addCheckboxField('enable_debug_logging');
$field->setLabel($addon->i18n('filepond_enable_debug_logging'));
$field->addOption($addon->i18n('filepond_enable_debug_logging_label'), 1);
$field->setNotice($addon->i18n('filepond_enable_debug_logging_notice'));

$form->addFieldset($addon->i18n('filepond_maintenance_section'));
$form->addRawField('
    <div class="form-group">
        <label class="control-label">' . $addon->i18n('filepond_maintenance_cleanup') . '</label>
        <div>
            <button type="button" class="btn btn-default" id="cleanup-temp-files" data-token="' . rex_escape($cleanupToken->getValue()) . '">
                <i class="fa fa-trash"></i> ' . $addon->i18n('filepond_maintenance_cleanup_button') . '
            </button>
            <span id="cleanup-status" class="help-block"></span>
        </div>
        <p class="help-block">' . $addon->i18n('filepond_maintenance_cleanup_notice') . '</p>
    </div>
');

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('filepond_settings_tab_system'));
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');
?>
<script nonce="<?= rex_response::getNonce() ?>">
(function () {
    var button = document.getElementById('cleanup-temp-files');
    if (!button) {
        return;
    }
    button.addEventListener('click', function () {
        var status = document.getElementById('cleanup-status');
        status.textContent = <?= json_encode($addon->i18n('filepond_maintenance_cleanup_running')) ?>;
        var body = new URLSearchParams({cleanup_temp: '1', _csrf_token: button.dataset.token});
        fetch(<?= json_encode(rex_url::currentBackendPage([], false)) ?>, {method: 'POST', body: body, headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (data) { status.textContent = data.message || ''; })
            .catch(function () { status.textContent = <?= json_encode($addon->i18n('filepond_maintenance_cleanup_error')) ?>; });
    });
})();
</script>
