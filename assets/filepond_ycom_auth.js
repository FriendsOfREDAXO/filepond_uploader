/**
 * FilePond YCom Media Auth Defaults
 *
 * Speichert die im Upload-Formular ausgewählten YCom-Media-Auth-Defaults
 * (Auth-Typ / Group-Typ / Gruppen) live in der Backend-Session und wendet
 * sie serverseitig auf jede neu hochgeladene Datei an.
 */
(function () {
    'use strict';

    var endpoint = 'index.php?rex-api-call=filepond_ycom_auth';

    function getValue(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
    }

    function getMultiValues(id) {
        var el = document.getElementById(id);
        if (!el || !el.options) {
            return [];
        }
        var values = [];
        for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].selected) {
                values.push(el.options[i].value);
            }
        }
        return values;
    }

    function buildFormData() {
        var fd = new FormData();
        fd.append('_csrf_token', (window.rex && window.rex.filepond_csrf) || '');
        fd.append('ycom_auth_type', getValue('filepond-ycom-auth-type') || '0');
        fd.append('ycom_group_type', getValue('filepond-ycom-group-type') || '0');
        getMultiValues('filepond-ycom-groups').forEach(function (v) {
            fd.append('ycom_groups[]', v);
        });
        return fd;
    }

    function save() {
        return fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: buildFormData()
        }).catch(function () { /* still */ });
    }

    /**
     * Sichtbarkeit der abhängigen Felder steuern.
     */
    function updateVisibility() {
        var authType = parseInt(getValue('filepond-ycom-auth-type') || '0', 10);
        var groupType = parseInt(getValue('filepond-ycom-group-type') || '0', 10);

        var groupTypeRow = document.getElementById('filepond-ycom-group-type-row');
        var groupsRow = document.getElementById('filepond-ycom-groups-row');

        if (groupTypeRow) {
            groupTypeRow.style.display = (authType === 1) ? '' : 'none';
        }
        if (groupsRow) {
            groupsRow.style.display = (authType === 1 && groupType > 0) ? '' : 'none';
        }
    }

    /**
     * Status-Badge im Panel-Header aktualisieren.
     */
    function updateBadge() {
        var badge = document.getElementById('filepond-ycom-auth-status');
        if (!badge) {
            return;
        }
        var authType = parseInt(getValue('filepond-ycom-auth-type') || '0', 10);
        if (authType === 1) {
            badge.textContent = badge.dataset.protectedText || 'eingeloggte';
            badge.classList.remove('label-default');
            badge.classList.add('label-warning');
        } else {
            badge.textContent = badge.dataset.publicText || 'öffentlich';
            badge.classList.remove('label-warning');
            badge.classList.add('label-default');
        }
    }

    function onChange() {
        updateVisibility();
        updateBadge();
        save();
    }

    function reset() {
        var auth = document.getElementById('filepond-ycom-auth-type');
        var groupType = document.getElementById('filepond-ycom-group-type');
        var groups = document.getElementById('filepond-ycom-groups');
        if (auth) { auth.value = '0'; }
        if (groupType) { groupType.value = '0'; }
        if (groups) {
            for (var i = 0; i < groups.options.length; i++) {
                groups.options[i].selected = false;
            }
        }
        onChange();
    }

    function init() {
        var ids = ['filepond-ycom-auth-type', 'filepond-ycom-group-type', 'filepond-ycom-groups'];
        var found = false;
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', onChange);
                found = true;
            }
        });

        var resetBtn = document.getElementById('filepond-ycom-auth-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function (e) {
                e.preventDefault();
                reset();
            });
        }

        if (found) {
            updateVisibility();
            updateBadge();
            // Initial-Sync, damit serverseitige Session und UI immer übereinstimmen.
            save();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
