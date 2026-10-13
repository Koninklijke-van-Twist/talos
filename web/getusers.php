<?php

declare(strict_types=1);

require_once __DIR__ . '/content/json_endpoint.php';
talosJsonEndpointStart();

require_once __DIR__ . '/content/bootstrap.php';


$currentEmail = function_exists('talosNormalizeEmail')
    ? talosNormalizeEmail((string) ($_SESSION['user']['email'] ?? ''))
    : strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$isIctUser = !empty($_SESSION['user']['admin'])
    || (function_exists('talosIsIctUserEmail') && talosIsIctUserEmail($currentEmail));

if (!$isIctUser) {
    talosJsonSend(['ok' => false, 'error' => 'Forbidden'], 403);
    exit;
}

try {
    // Graph-paging kan bij veel gebruikers lang duren.
    @set_time_limit(120);
    $users = include __DIR__ . '/getusers_fetch.php';
    if (!is_array($users)) {
        $users = [];
    }

    talosJsonSend(array_values($users));
} catch (Throwable $e) {
    error_log('[Talos getusers] ' . $e);
    talosJsonSend([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
