<?php

declare(strict_types=1);

require_once __DIR__ . '/content/bootstrap.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/content/project_billing.php';
require_once __DIR__ . '/content/department_access.php';
require_once __DIR__ . '/odata.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$currentEmail = talosNormalizeEmail((string) ($_SESSION['user']['email'] ?? ''));
$isIctUser = !empty($_SESSION['user']['admin']) || talosIsIctUserEmail($currentEmail);

if (!$isIctUser) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'list'));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($action === 'list') {
        $accessMap = talosLoadDepartmentAccessMap();
        $selectedCompany = trim((string) ($_GET['company'] ?? ''));
        $departments = talosResolveDepartmentOptions(
            (string) $baseUrl,
            $environment,
            $auth,
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

        echo json_encode([
            'ok' => true,
            'departments' => $departments,
            'users' => $users,
            'groups' => talosGroupUsersByDepartmentSet($accessMap['users'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'save') {
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
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
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid email'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (talosIsIctUserEmail($email)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ICT users always have all departments'], JSON_UNESCAPED_UNICODE);
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
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Save failed'], JSON_UNESCAPED_UNICODE);
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

        echo json_encode([
            'ok' => true,
            'email' => $email,
            'departments' => $accessMap['users'][$email] ?? [],
            'removed' => !isset($accessMap['users'][$email]),
            'users' => $users,
            'groups' => talosGroupUsersByDepartmentSet($accessMap['users'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
