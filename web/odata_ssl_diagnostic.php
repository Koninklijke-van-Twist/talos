<?php

/**
 * Includes/requires
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content/helpers.php';

/**
 * Variabelen
 */

$incomingKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '');

/**
 * Functies
 */

function talosBuildSslDiagnosticHints(string $curlError): array
{
    $error = strtolower($curlError);
    $hints = [];

    if (str_contains($error, 'certificate') || str_contains($error, 'ssl')) {
        $hints[] = 'Controleer de lokale CA-bundle voor PHP/cURL (curl.cainfo en openssl.cafile).';
        $hints[] = 'Vergelijk certificaatketen lokaal met de server waar het wel werkt.';
    }

    if (str_contains($error, 'issuer')) {
        $hints[] = 'Waarschijnlijk ontbreekt een intermediate of root CA in de lokale trust store.';
    }

    if (str_contains($error, 'host') || str_contains($error, 'name')) {
        $hints[] = 'Controleer of hostname en certificaat-SAN exact overeenkomen.';
    }

    if ($hints === []) {
        $hints[] = 'Controleer netwerk/proxy en TLS-inspectie op je lokale machine.';
    }

    return $hints;
}

/**
 * Page load
 */

header('Content-Type: application/json; charset=UTF-8');

if (!validateApiKey($incomingKey, $apiKeys)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$activeEnvironment = function_exists('getPrimaryEnvironment')
    ? getPrimaryEnvironment()
    : (is_array($environment) ? (string) ($environment[0] ?? '') : (string) $environment);
$authForEnvironment = function_exists('getAuthForEnvironment')
    ? getAuthForEnvironment($activeEnvironment)
    : $auth;

$url = buildOdataMetadataUrl($baseUrl, $activeEnvironment);
$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Talos-SSL-Diagnostic/1.0',
    CURLOPT_HTTPHEADER => [
        'Accept: application/xml, application/json',
        'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8',
    ],
]);

if (($authForEnvironment['mode'] ?? '') === 'basic') {
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $authForEnvironment['user'] . ':' . $authForEnvironment['pass']);
} elseif (($authForEnvironment['mode'] ?? '') === 'ntlm') {
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
    curl_setopt($ch, CURLOPT_USERPWD, $authForEnvironment['user'] . ':' . $authForEnvironment['pass']);
}

$raw = curl_exec($ch);
$curlErrorNo = curl_errno($ch);
$curlError = curl_error($ch);
$info = curl_getinfo($ch);
$sslVerifyResult = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
curl_close($ch);

$ok = ($raw !== false) && (($info['http_code'] ?? 0) >= 200) && (($info['http_code'] ?? 0) < 300);

$payload = [
    'ok' => $ok,
    'timestamp' => gmdate('c'),
    'request' => [
        'url' => $url,
        'environment' => $activeEnvironment,
        'auth_mode' => (string) ($authForEnvironment['mode'] ?? ''),
    ],
    'curl' => [
        'error_no' => $curlErrorNo,
        'error' => $curlError,
        'http_code' => (int) ($info['http_code'] ?? 0),
        'ssl_verify_result' => $sslVerifyResult,
        'primary_ip' => (string) ($info['primary_ip'] ?? ''),
        'local_ip' => (string) ($info['local_ip'] ?? ''),
        'total_time' => (float) ($info['total_time'] ?? 0),
        'namelookup_time' => (float) ($info['namelookup_time'] ?? 0),
        'connect_time' => (float) ($info['connect_time'] ?? 0),
        'appconnect_time' => (float) ($info['appconnect_time'] ?? 0),
    ],
    'php' => [
        'version' => PHP_VERSION,
        'curl_cainfo' => (string) ini_get('curl.cainfo'),
        'openssl_cafile' => (string) ini_get('openssl.cafile'),
        'openssl_capath' => (string) ini_get('openssl.capath'),
    ],
    'versions' => [
        'curl' => curl_version(),
    ],
    'hints' => talosBuildSslDiagnosticHints($curlError),
];

if ($ok && is_string($raw)) {
    $payload['response_preview'] = substr($raw, 0, 300);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;