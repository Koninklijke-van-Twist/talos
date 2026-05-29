<?php

const DEBUG_EVERYONE_IS_ADMIN = false;

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

    $_SESSION['user']['admin'] = false;

    if (
        DEBUG_EVERYONE_IS_ADMIN ||
        array_any($ictUsers, function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        $_SESSION['user']['admin'] = true;
    }

    if (
        isset($allowedUsers) &&
        !array_any($allowedUsers, function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        require __DIR__ . "/../login/403.php";
        die();
    }

} else {
    $_SESSION['user'] = [
        'email' => 'localtester@kvt.nl',
        'name' => (string) ('Local Tester'),
        'oid' => (string) ('12345'),
        'admin' => true,
    ];
}