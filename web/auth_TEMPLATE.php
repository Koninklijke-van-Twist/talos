<?php
/**
 * Auth template for Talos.
 *
 * Prefer Mímir, and keep the BC block as automatic fallback when Mímir is down:
 *   $mimirApi  = 'mimir_…';  // required to activate Mímir
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optional
 *
 * With $mimirApi set, fetches try Mímir first and fall back to the BC vars below.
 * Without $mimirApi, only the BC block is used.
 * Keep $baseUrl, $environment, $auth_list and $auth in auth.php next to $mimirApi.
 */

// --- Mímir (recommended) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct path, and fallback when Mímir fails) ---
$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
    ];
// String, of een lijst wanneer Talos meerdere BC-environments leest.
$environment = "env1";
$auth = $auth_list[$environment];
$baseUrl = "https://my-bc-domain.com:7148/";

$allowedUsers = [
    "user@domain.nl"
];
