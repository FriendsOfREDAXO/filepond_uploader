<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\FilePondUploader\Api;

use rex;
use FriendsOfRedaxo\FilePondUploader\YcomAuthSettings;
use rex_api_function;
use rex_backend_login;
use rex_response;


/**
 * API-Endpoint zum Speichern der YCom-Media-Auth-Defaults pro Backend-Session.
 *
 * Wird vom Upload-Formular aufgerufen, sobald ein berechtigter Backend-User
 * eines der drei Felder (Auth-Typ, Group-Typ, Gruppen) verändert.
 */
class YcomAuth extends rex_api_function
{
    protected $published = false;

    public function execute()
    {
        rex_response::cleanOutputBuffers();

        // Nur POST – mutiert Session-State, daher GET/HEAD ablehnen.
        if ('POST' !== strtoupper((string) rex_server('REQUEST_METHOD', 'string', ''))) {
            rex_response::setStatus('405 Method Not Allowed');
            rex_response::sendJson(['error' => 'method_not_allowed']);
            exit;
        }

        if (!rex_backend_login::hasSession()) {
            rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
            rex_response::sendJson(['error' => 'forbidden']);
            exit;
        }

        $user = rex::getUser();
        if (!YcomAuthSettings::isEnabled() || !YcomAuthSettings::userMayManage($user)) {
            rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
            rex_response::sendJson(['error' => 'forbidden']);
            exit;
        }

        $authType = rex_request('ycom_auth_type', 'int', 0);
        $groupType = rex_request('ycom_group_type', 'int', 0);
        $groupsRaw = rex_request('ycom_groups', 'array', []);

        $groups = [];
        foreach ($groupsRaw as $g) {
            $gid = (int) $g;
            if ($gid > 0) {
                $groups[] = $gid;
            }
        }

        YcomAuthSettings::setSessionDefaults([
            'ycom_auth_type' => $authType,
            'ycom_group_type' => $groupType,
            'ycom_groups' => $groups,
        ]);

        rex_response::sendJson([
            'success' => true,
            'defaults' => YcomAuthSettings::getSessionDefaults(),
        ]);
        exit;
    }
}
