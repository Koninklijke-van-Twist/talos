<?php

declare(strict_types=1);

require_once __DIR__ . '/content/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$currentEmail = function_exists('talosNormalizeEmail')
    ? talosNormalizeEmail((string) ($_SESSION['user']['email'] ?? ''))
    : strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$isIctUser = !empty($_SESSION['user']['admin'])
    || (function_exists('talosIsIctUserEmail') && talosIsIctUserEmail($currentEmail));

if (!$isIctUser) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $users = include __DIR__ . '/getusers_fetch.php';
    if (!is_array($users)) {
        $users = [];
    }

    echo json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
