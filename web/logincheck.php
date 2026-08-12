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

} else {
    $_SESSION['user'] = [
        'email' => 'localtester@kvt.nl',
        'name' => (string) ('Local Tester'),
        'oid' => (string) ('12345'),
        'admin' => true,
        'allowed_departments' => null,
    ];
}
