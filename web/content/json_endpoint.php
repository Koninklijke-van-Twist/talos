<?php

declare(strict_types=1);

/**
 * Helpers voor JSON-endpoints: altijd geldige JSON terug, ook bij warnings,
 * notices of fatals. Uitvoer die per ongeluk vóór de JSON komt, gaat naar error_log.
 */

function talosJsonEndpointStart(): void
{
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');

    $GLOBALS['talos_json_sent'] = false;
    ob_start();

    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['talos_json_sent'])) {
            return;
        }

        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        $isFatal = is_array($lastError) && in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true);
        // Geen fatal (bijv. login-redirect of 403-pagina via logincheck): uitvoer ongemoeid laten.
        if (!$isFatal) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            return;
        }

        talosJsonSend([
            'ok' => false,
            'error' => 'PHP fatal: ' . (string) ($lastError['message'] ?? '')
                . ' (' . basename((string) ($lastError['file'] ?? '')) . ':' . (int) ($lastError['line'] ?? 0) . ')',
        ], 500);
    });
}

/**
 * Lees alle buffered uitvoer weg, log hem, en stuur de JSON.
 */
function talosJsonSend($payload, int $status = 200): void
{
    $stray = '';
    while (ob_get_level() > 0) {
        $stray .= (string) ob_get_clean();
    }
    if (trim($stray) !== '') {
        error_log('[Talos JSON endpoint ' . (string) ($_SERVER['SCRIPT_NAME'] ?? '') . '] onverwachte uitvoer: ' . substr(trim($stray), 0, 2000));
    }

    $GLOBALS['talos_json_sent'] = true;
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        $json = json_encode(['ok' => false, 'error' => 'JSON-encode mislukt: ' . json_last_error_msg()]);
    }

    echo $json;
}
