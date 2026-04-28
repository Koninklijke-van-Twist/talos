<?php

/**
 * Includes/requires
 */

function talosErrorTypeToString(int $type): string
{
    return match ($type) {
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_STRICT => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        default => 'UNKNOWN',
    };
}

function talosGetErrorDumpDir(): ?string
{
    $candidates = [
        __DIR__ . '/data/php_errors',
        __DIR__ . '/errors/php_errors',
        rtrim(sys_get_temp_dir(), '\\/') . '/talos_php_errors',
    ];

    foreach ($candidates as $dir) {
        if (is_dir($dir) || mkdir($dir, 0750, true)) {
            return $dir;
        }
    }

    return null;
}

function talosWriteErrorDump(string $kind, string $message, string $file, int $line, ?int $severity = null, array $context = []): void
{
    $dir = talosGetErrorDumpDir();
    if ($dir === null) {
        error_log('[Talos error dump] Failed to resolve writable dump directory');
        return;
    }

    $timestamp = gmdate('Ymd_His');
    $micro = str_replace('.', '', sprintf('%.6f', microtime(true)));
    $path = $dir . '/error_' . $timestamp . '_' . $micro . '.txt';

    $content = [
        'timestamp=' . gmdate('c'),
        'kind=' . $kind,
        'message=' . $message,
        'file=' . $file,
        'line=' . (string) $line,
        'severity=' . ($severity !== null ? talosErrorTypeToString($severity) : ''),
        'request_uri=' . (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'request_method=' . (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'remote_addr=' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'session_id=' . (function_exists('session_id') ? (string) session_id() : ''),
        'user_email=' . (string) ($_SESSION['user']['email'] ?? ''),
    ];

    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $content[] = (string) $key . '=' . (string) $value;
        }
    }

    $ok = file_put_contents($path, implode("\n", $content) . "\n", LOCK_EX);
    if ($ok === false) {
        error_log('[Talos error dump] Failed writing dump file: ' . $path);
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../logincheck.php';

// login/lib.php roept session_write_close() aan; heropen de sessie zodat
// $_SESSION-schrijfacties (zoals getCsrfToken) wel worden opgeslagen.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Page load
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    talosWriteErrorDump('php_error', $message, $file, $line, $severity);
    return false;
});

set_exception_handler(static function (Throwable $exception): void {
    talosWriteErrorDump(
        'uncaught_exception',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if (!is_array($lastError)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $type = (int) ($lastError['type'] ?? 0);
    if (!in_array($type, $fatalTypes, true)) {
        return;
    }

    talosWriteErrorDump(
        'fatal_error',
        (string) ($lastError['message'] ?? ''),
        (string) ($lastError['file'] ?? ''),
        (int) ($lastError['line'] ?? 0),
        $type
    );
});
