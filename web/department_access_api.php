<?php

declare(strict_types=1);

require_once __DIR__ . '/content/json_endpoint.php';
talosJsonEndpointStart();

require_once __DIR__ . '/content/bootstrap.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/content/project_billing.php';
require_once __DIR__ . '/content/department_access.php';
require_once __DIR__ . '/odata.php';

// odata.php zet display_errors aan; voor dit JSON-endpoint weer uit.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');


$currentEmail = talosNormalizeEmail((string) ($_SESSION['user']['email'] ?? ''));
$isIctUser = !empty($_SESSION['user']['admin']) || talosIsIctUserEmail($currentEmail);

if (!$isIctUser) {
    talosJsonSend(['ok' => false, 'error' => 'Forbidden'], 403);
    exit;
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($action === 'list') {
        $accessMap = talosLoadDepartmentAccessMap();
        $selectedCompany = trim((string) ($_GET['company'] ?? ''));
        $departments = talosResolveDepartmentOptions(
            (string) ($baseUrl ?? ''),
            $environment ?? [],
            is_array($auth ?? null) ? $auth : [],
            [],
            $selectedCompany,
            $accessMap
        );

        $users = [];
        foreach (($accessMap['users'] ?? []) as $email => $codes) {
            $users[] = [
                'email' => (string) $email,
                'departments' => array_values((array) $codes),
            ];
        }

        talosJsonSend([
            'ok' => true,
            'departments' => $departments,
            'users' => $users,
            'groups' => talosGroupUsersByDepartmentSet($accessMap['users'] ?? []),
        ]);
        exit;
    }

    if ($action === 'save') {
        if ($method !== 'POST') {
            talosJsonSend(['ok' => false, 'error' => 'Method not allowed'], 405);
            exit;
        }

        $rawBody = (string) file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $email = talosNormalizeEmail((string) ($payload['email'] ?? ''));
        $departments = $payload['departments'] ?? [];
        if (!is_array($departments)) {
            $departments = [];
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            talosJsonSend(['ok' => false, 'error' => 'Invalid email'], 400);
            exit;
        }

        if (talosIsIctUserEmail($email)) {
            talosJsonSend(['ok' => false, 'error' => 'ICT users always have all departments'], 400);
            exit;
        }

        $accessMap = talosLoadDepartmentAccessMap();
        $codes = talosNormalizeDepartmentCodes($departments);
        if ($codes === []) {
            unset($accessMap['users'][$email]);
        } else {
            $accessMap['users'][$email] = $codes;
        }

        if (!talosSaveDepartmentAccessMap($accessMap)) {
            talosJsonSend(['ok' => false, 'error' => 'Save failed'], 500);
            exit;
        }

        $accessMap = talosLoadDepartmentAccessMap();
        $users = [];
        foreach (($accessMap['users'] ?? []) as $userEmail => $userCodes) {
            $users[] = [
                'email' => (string) $userEmail,
                'departments' => array_values((array) $userCodes),
            ];
        }

        talosJsonSend([
            'ok' => true,
            'email' => $email,
            'departments' => $accessMap['users'][$email] ?? [],
            'removed' => !isset($accessMap['users'][$email]),
            'users' => $users,
            'groups' => talosGroupUsersByDepartmentSet($accessMap['users'] ?? []),
        ]);
        exit;
    }

    talosJsonSend(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[Talos department_access_api] ' . $e);
    talosJsonSend([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
