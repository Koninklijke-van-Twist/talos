<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/talos-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['TALOS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Talos] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$environment = ['Production'];
if (odata_bc_environment() !== 'Production' || odata_bc_credentials_configured() !== true) {
    fail('array-$environment moet de BC-fallback nog configureren');
}
$environment = 'Production';

if (!odata_mimir_enabled()) {
    fail('Mímir moet aan staan zolang $mimirApi gezet is');
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$directCompanyUrl = odata_bc_url_from_odata_url(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No"
);
if (strpos($directCompanyUrl, "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?") !== 0) {
    fail('na de circuit-open moet een synthetische Mímir-URL de oude BC-URL worden, kreeg: ' . $directCompanyUrl);
}
if (strpos($directCompanyUrl, 'mimir.invalid') !== false) {
    fail('synthetische host bleef staan na fallback');
}
$relativeCompanyUrl = odata_bc_url_from_odata_url(
    "/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No"
);
if ($relativeCompanyUrl !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('lege baseUrl moet bij fallback de BC-host krijgen, kreeg: ' . $relativeCompanyUrl);
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() < 2) {
    fail('elke fallback moet gelogd worden, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Talos] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'prod-user', 'pass' => 'prod-secret'],
    'Sandbox' => ['mode' => 'basic', 'user' => 'sand-user', 'pass' => 'sand-secret'],
];
$environment = 'Production';
$GLOBALS['talos_bc_company_environments'] = ['KVT Gas' => 'Sandbox'];
odata_mimir_circuit_reset();
$beforeCompanyEnv = count($calls);
$companyEnvRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$companyEnvCall = $calls[$beforeCompanyEnv] ?? null;
if (($companyEnvRows[0]['No'] ?? '') !== 'WO-1' || !is_array($companyEnvCall) || strpos($companyEnvCall['url'], "/Sandbox/ODataV4/Company('KVT%20Gas')/AppResource?") === false || $companyEnvCall['user'] !== 'sand-user') {
    fail('query-fallback moet het environment van het bedrijf en die auth_list-entry gebruiken: ' . json_encode($companyEnvCall));
}

odata_mimir_circuit_reset();
$beforeSandbox = count($calls);
$sandboxRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    $auth_list['Production'],
    15
);
$sandboxCall = $calls[$beforeSandbox] ?? null;
if (($sandboxRows[0]['No'] ?? '') !== 'WO-1' || !is_array($sandboxCall) || strpos($sandboxCall['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company(') !== 0 || $sandboxCall['user'] !== 'sand-user') {
    fail('entity-fallback moet Sandbox-auth gebruiken, niet de primaire: ' . json_encode($sandboxCall));
}

if (!function_exists('getActiveEnvironments')) {
    function getActiveEnvironments(): array
    {
        if (function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open()) {
            fail('cache-key mag tijdens fallback getActiveEnvironments niet aanroepen');
        }
        global $environment;
        if (is_array($environment)) {
            return array_values(array_map('strval', $environment));
        }
        $single = trim((string) $environment);
        if ($single === '' || strcasecmp($single, 'mimir') === 0) {
            return [];
        }
        return [$single];
    }
}
$environment = 'mimir';
$cacheKey = build_cache_key(
    "https://bc.example:7148/Sandbox/ODataV4/Company('KVT%20Gas')/AppResource?\$select=No",
    $auth_list['Sandbox']
);
if (strpos($cacheKey, '|sand-user|Sandbox') === false || strpos($cacheKey, 'mimir') !== false) {
    fail('cache-key moet de echte BC-environment gebruiken, kreeg: ' . $cacheKey);
}

$authImportPath = sys_get_temp_dir() . '/talos-auth-import-' . getmypid() . '.php';
file_put_contents(
    $authImportPath,
    "<?php\n\$baseUrl = 'https://imported.example:7148/';\n\$environment = 'Sandbox';\n\$auth_list = ['Sandbox' => ['mode' => 'basic', 'user' => 'from-file', 'pass' => 'file-secret']];\n\$auth = \$auth_list['Sandbox'];\n\$mimirApi = 'mimir_from_file';\n"
);
$savedGlobals = [];
foreach (['baseUrl', 'environment', 'auth', 'auth_list', 'mimirApi', 'mimirBase'] as $globalName) {
    $savedGlobals[$globalName] = array_key_exists($globalName, $GLOBALS) ? $GLOBALS[$globalName] : null;
}
talos_import_web_auth_php($authImportPath);
if (($GLOBALS['baseUrl'] ?? '') !== 'https://imported.example:7148/' || ($GLOBALS['environment'] ?? '') !== 'Sandbox' || ($GLOBALS['auth_list']['Sandbox']['user'] ?? '') !== 'from-file' || ($GLOBALS['mimirApi'] ?? '') !== 'mimir_from_file') {
    fail('lazy auth.php moet BC-variabelen naar $GLOBALS kopiëren');
}
foreach ($savedGlobals as $globalName => $savedValue) {
    if ($savedValue === null) {
        unset($GLOBALS[$globalName]);
    } else {
        $GLOBALS[$globalName] = $savedValue;
    }
}
@unlink($authImportPath);
$baseUrl = $GLOBALS['baseUrl'];
$environment = $GLOBALS['environment'];
$auth = $GLOBALS['auth'];
$auth_list = $GLOBALS['auth_list'];
$mimirApi = $GLOBALS['mimirApi'];

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('hergooide fout ontbreekt');
}
if (strpos($rethrown->getMessage(), 'Mímir') !== 0 && strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'only-auth', 'pass' => 'only-secret'];
unset($auth_list);
unset($GLOBALS['auth_list']);
$GLOBALS['talos_bc_company_environments'] = [];

$beforeSoloCompanies = count($calls);
$soloNames = odata_mimir_list_companies(null);
$soloCompanyCall = $calls[$beforeSoloCompanies] ?? null;
if ($soloNames !== $expectedNames || !is_array($soloCompanyCall) || strpos($soloCompanyCall['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0 || $soloCompanyCall['user'] !== 'only-auth') {
    fail('companylijst zonder $auth_list moet de primaire environment met $auth vragen: ' . json_encode($soloCompanyCall));
}

$GLOBALS['talos_bc_company_environments'] = [];
$beforeSoloQuery = count($calls);
$soloQuery = odata_mimir_query('Los Bedrijf', 'AppResource', ['$select' => 'No'], 30);
$soloQueryCall = $calls[$beforeSoloQuery] ?? null;
if (($soloQuery[0]['No'] ?? '') !== 'WO-1' || !is_array($soloQueryCall) || strpos($soloQueryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Los%20Bedrijf')/AppResource?") !== 0 || $soloQueryCall['user'] !== 'only-auth') {
    fail('query zonder $auth_list moet $auth naar de primaire environment sturen: ' . json_encode($soloQueryCall));
}

$beforeSoloFetch = count($calls);
$soloFetch = odata_mimir_fetch_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
$soloFetchCall = $calls[$beforeSoloFetch] ?? null;
if (($soloFetch[0]['No'] ?? '') !== 'WO-1' || !is_array($soloFetchCall) || strpos($soloFetchCall['url'], "https://bc.example:7148/Sandbox/ODataV4/Company('KVT%20Gas')/AppWerkorders?") !== 0 || $soloFetchCall['user'] !== 'only-auth') {
    fail('URL-fetch zonder $auth_list moet elk environment met $auth doen: ' . json_encode($soloFetchCall));
}

$auth_list = [];
$environment = ['Production', 'Sandbox'];
$GLOBALS['talos_bc_company_environments'] = [];
odata_mimir_circuit_reset();
$beforeEmptyCompanies = count($calls);
$emptyNames = odata_mimir_list_companies(null);
$emptyCompanyCalls = array_slice($calls, $beforeEmptyCompanies);
if ($emptyNames !== $expectedNames || count($emptyCompanyCalls) !== 2) {
    fail('lege $auth_list moet elke environment in de companylijst met $auth bevragen: ' . json_encode($emptyCompanyCalls));
}
foreach ($emptyCompanyCalls as $emptyCompanyCall) {
    if (($emptyCompanyCall['user'] ?? '') !== 'only-auth' || strpos((string) ($emptyCompanyCall['url'] ?? ''), '/ODataV4/Company') === false) {
        fail('lege $auth_list gebruikte niet $auth voor de companylijst: ' . json_encode($emptyCompanyCalls));
    }
}
$emptyUrls = array_map(static function (array $call): string {
    return (string) ($call['url'] ?? '');
}, $emptyCompanyCalls);
if (strpos($emptyUrls[0], '/Production/ODataV4/Company') === false || strpos($emptyUrls[1], '/Sandbox/ODataV4/Company') === false) {
    fail('lege $auth_list moet Production en Sandbox bevragen: ' . json_encode($emptyUrls));
}

odata_mimir_circuit_reset();
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'primary-user', 'pass' => 'primary-secret'];
$auth_list = [
    'Sandbox' => ['mode' => 'basic', 'user' => 'sand-user', 'pass' => 'sand-secret'],
];
$GLOBALS['talos_bc_company_environments'] = [];
$beforeUnmapped = count($calls);
$unmappedRows = odata_mimir_query('Los Bedrijf', 'AppResource', ['$select' => 'No'], 30);
$unmappedCall = $calls[$beforeUnmapped] ?? null;
if (($unmappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($unmappedCall) || strpos($unmappedCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Los%20Bedrijf')/AppResource?") !== 0 || $unmappedCall['user'] !== 'primary-user') {
    fail('unmapped bedrijf moet via $auth naar de primaire environment: ' . json_encode($unmappedCall));
}

$environment = ['Production', 'Sandbox'];
$GLOBALS['talos_bc_company_environments'] = ['KVT Gas' => 'Production'];
odata_mimir_circuit_reset();
$beforePrimaryMapped = count($calls);
$primaryMappedRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$primaryMappedCall = $calls[$beforePrimaryMapped] ?? null;
if (($primaryMappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($primaryMappedCall) || strpos($primaryMappedCall['url'], "/Production/ODataV4/Company('KVT%20Gas')/AppResource?") === false || $primaryMappedCall['user'] !== 'primary-user') {
    fail('primaire environment zonder eigen auth_list-entry moet $auth gebruiken: ' . json_encode($primaryMappedCall));
}

odata_mimir_circuit_reset();
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'primary-user', 'pass' => 'primary-secret'];
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'prod-user', 'pass' => 'prod-secret'],
];
$GLOBALS['talos_bc_company_environments'] = [];
$callsBeforeSandboxRefuse = count($calls);
$sandboxRefused = null;
try {
    odata_mimir_fetch_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
        15
    );
    fail('Sandbox-URL zonder auth_list-entry moet de Mímir-fout teruggeven');
} catch (Throwable $exception) {
    $sandboxRefused = $exception;
}
if (!$sandboxRefused instanceof Throwable || strpos($sandboxRefused->getMessage(), 'Mímir') === false) {
    fail('Sandbox-weigering is niet de oorspronkelijke Mímir-fout: ' . ($sandboxRefused instanceof Throwable ? $sandboxRefused->getMessage() : 'geen fout'));
}
if (count($calls) !== $callsBeforeSandboxRefuse) {
    fail('Sandbox-URL zonder eigen entry mag geen BC-call doen');
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'kept-auth', 'pass' => 'kept-secret'];
unset($auth_list);
unset($GLOBALS['auth_list']);
require_once dirname(__DIR__) . '/web/authhelper.php';
if (($auth['user'] ?? '') !== 'kept-auth') {
    fail('authhelper mag een bestaande $auth niet wissen als $auth_list ontbreekt: ' . json_encode($auth));
}
if (getAuthForEnvironment('Production') !== []) {
    fail('authhelper moet zonder auth_list-entry nog steeds een lege sentinel teruggeven');
}

echo "OK\n";
