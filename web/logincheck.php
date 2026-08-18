<?php

const DEBUG_EVERYONE_IS_ADMIN = false;

require_once __DIR__ . '/content/department_access.php';

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }
    return false;
}

// login/lib.php expects an active session (with cookie user) before it runs.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!is_trusted_requester()) {
    require __DIR__ . "/../login/lib.php";

    // login/lib.php can close the session; reopen it before writing admin state.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $currentEmail = talosNormalizeEmail((string) ($_SESSION['user']['email'] ?? ''));
    $_SESSION['user']['admin'] = false;
    $_SESSION['user']['allowed_departments'] = [];

    $isIctUser = DEBUG_EVERYONE_IS_ADMIN || talosIsIctUserEmail($currentEmail);

    if ($isIctUser) {
        $_SESSION['user']['admin'] = true;
        $_SESSION['user']['allowed_departments'] = null;
    } else {
        $departments = talosDepartmentsForEmail($currentEmail);
        if ($departments === []) {
            require __DIR__ . "/../login/403.php";
            die();
        }

        $_SESSION['user']['allowed_departments'] = $departments;
    }

    if (isset($allowedUsers)) {
        $sessionEmail = strtolower((string) ($_SESSION['user']['email'] ?? ''));
        $isAllowedUser = false;
        foreach ($allowedUsers as $email) {
            if (strtolower((string) $email) === $sessionEmail) {
                $isAllowedUser = true;
                break;
            }
        }
        if (!$isAllowedUser) {
            require __DIR__ . "/../login/403.php";
            die();
        }
    }

    $analyticsEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $analyticsApiKey = trim((string) ($_SESSION['user']['api_key'] ?? ''));
    $analyticsOid = strtolower(trim((string) ($_SESSION['user']['oid'] ?? '')));
    if ($analyticsEmail !== '' && $analyticsApiKey !== '' && $analyticsOid !== '') {
        $analyticsScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $analyticsHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $analyticsBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $analyticsUrl = $analyticsScheme . '://' . $analyticsHost . $analyticsBase . '/analytics/analytics.php?' . http_build_query([
            'user_email' => $analyticsEmail,
            'api_key' => $analyticsApiKey,
            'oid' => $analyticsOid,
        ], '', '&', PHP_QUERY_RFC3986);

        if (function_exists('curl_init')) {
            $analyticsCurl = curl_init($analyticsUrl);
            curl_setopt_array($analyticsCurl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 1,
                CURLOPT_HTTPHEADER => ['X-API-Key: ' . $analyticsApiKey],
            ]);
            curl_exec($analyticsCurl);
            curl_close($analyticsCurl);
        }
    }

} else {
    $_SESSION['user'] = [
        'email' => 'localtester@kvt.nl',
        'name' => (string) ('Local Tester'),
        'oid' => (string) ('12345'),
        'admin' => true,
        'allowed_departments' => null,
    ];
}
