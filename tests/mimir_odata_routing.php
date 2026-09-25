<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php tests/mimir_odata_routing.php
 */

/**
 * Includes/requires
 */

require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/content/helpers.php';
require_once __DIR__ . '/../web/content/project_billing.php';

/**
 * Variabelen
 */

$failures = 0;
$mockPort = 18941;
$mockLog = sys_get_temp_dir() . '/talos-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/talos-mimir-mock.php';
$authPath = __DIR__ . '/../web/auth.php';
$authExistedBefore = false;
$authBackup = null;
$authWritten = false;

/**
 * Functies
 */

function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function test_write_mock(): void
{
    global $mockScript, $mockLog, $mockPort;
    $log = var_export($mockLog, true);
    $port = (int) $mockPort;
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir/api/redirect.php')) {
    header('Location: http://127.0.0.1:MOCK_PORT/mimir/api/companies.php', true, 302);
    echo json_encode(['error' => 'redirect']);
    exit;
}

if (str_contains($uri, '/mimir-dup/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Overlap BV', 'environment' => 'Production'],
        ['name' => 'Overlap BV', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    echo json_encode(['value' => [[
        'No' => 'PRJ1',
        'Job_No' => 'PRJ1',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
    ]]]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    $php = str_replace(['LOG_PATH', 'MOCK_PORT'], [$log, (string) $port], $php);
    file_put_contents($mockScript, $php);
}

/**
 * Zet auth.php terug. Verwijdert het bestand alleen als deze test het zelf heeft aangemaakt.
 */
function test_restore_auth_php(string $path, bool $existedBefore, ?string $backup, bool $written): void
{
    if (!$written) {
        return;
    }

    if ($existedBefore) {
        if (!is_string($backup)) {
            return;
        }
        file_put_contents($path, $backup);
        return;
    }

    @unlink($path);
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

function test_reset_mimir_cache(): void
{
    unset($GLOBALS['talos_mimir_active_environments']);
}

/**
 * Page load
 */

test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert('default Mímir-base', odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api');

$spaceUrl = buildOdataCompanyUrl('  https://bc.example/  ', 'Production', 'Koninklijke van Twist')
    . 'Projecten?$select=No,Description&$filter=' . rawurlencode("No eq 'PRJ1'");
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'entity-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'Projecten'
        && ($parsedSpace['query']['$select'] ?? '') === 'No,Description'
        && ($parsedSpace['query']['$filter'] ?? '') === "No eq 'PRJ1'",
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert('entity-URL is geen company-discovery', odata_mimir_parse_companies_url($spaceUrl) === null);

$apostropheUrl = buildOdataCompanyUrl('', 'Production', "Van Twist's")
    . 'FactureerbareProjectPlanningsRegels?$select=Job_No';
$parsedApostrophe = odata_mimir_parse_entity_url($apostropheUrl);
test_assert(
    'lege baseUrl en apostrof in bedrijfsnaam',
    is_array($parsedApostrophe)
        && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
        && ($parsedApostrophe['entity'] ?? '') === 'FactureerbareProjectPlanningsRegels'
        && ($parsedApostrophe['query']['$select'] ?? '') === 'Job_No',
    json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE)
);

$companiesUrl = buildOdataRootUrl('', 'Production') . 'Company?$select=Name,Display_Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'Production',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

test_write_mock();
@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
usleep(200000);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
$baseUrl = '';
unset($environment, $auth_list, $auth);
test_reset_mimir_cache();

$authExistedBefore = is_file($authPath);
$authBackup = null;
if ($authExistedBefore) {
    $authRaw = file_get_contents($authPath);
    $authBackup = is_string($authRaw) ? $authRaw : null;
}

try {
    require_once __DIR__ . '/../web/authhelper.php';

    test_assert('authhelper zonder BC-config faalt niet in Mímir-modus', is_array($auth) && $auth === []);

    $context = fetchAvailableCompanyContext('', [], []);
    test_assert(
        'Mímir company-discovery zonder BC-creds',
        ($context['available_companies'] ?? []) === ['Hunter van Twist', 'Koninklijke van Twist', "Van Twist's"]
            && ($context['company_environment_map']['Koninklijke van Twist'] ?? '') === 'Production'
            && ($context['company_environment_map']['Hunter van Twist'] ?? '') === 'Sandbox'
            && ($context['company_environment_map']["Van Twist's"] ?? '') === 'Production',
        json_encode($context, JSON_UNESCAPED_UNICODE)
    );

    test_assert('lege auth-sentinel in Mímir-modus', getAuthForEnvironment('Production') === []);

    unset($environment);
    test_reset_mimir_cache();
    $active = getActiveEnvironments();
    test_assert(
        'environments uit Mímir als auth_list ontbreekt',
        $active === ['Production', 'Sandbox'],
        json_encode($active)
    );

    $environment = ['Sandbox'];
    test_assert(
        'expliciet environment blijft leidend',
        getActiveEnvironments() === ['Sandbox'],
        json_encode(getActiveEnvironments())
    );
    $sandboxOnly = fetchAvailableCompanyContext('', ['Sandbox'], []);
    test_assert(
        'company-discovery filtert op opgegeven environment',
        ($sandboxOnly['available_companies'] ?? []) === ['Hunter van Twist'],
        json_encode($sandboxOnly, JSON_UNESCAPED_UNICODE)
    );
    unset($environment);

    $redirectThrew = false;
    $redirectMessage = '';
    try {
        odata_mimir_request('GET', 'redirect.php');
    } catch (Exception $error) {
        $redirectThrew = str_contains($error->getMessage(), 'HTTP 302');
        $redirectMessage = $error->getMessage();
    }
    test_assert('Mímir volgt geen redirect', $redirectThrew, $redirectMessage);

    $beforeCache = glob(__DIR__ . '/../web/cache/odata/*.json') ?: [];
    $queryUrl = buildOdataCompanyUrl('', 'Production', "Van Twist's")
        . 'FactureerbareProjectPlanningsRegels?$select=Job_No&$filter=' . rawurlencode("No eq 'PRJ1'");
    $rows = odata_get_all($queryUrl, [], 60);
    test_assert(
        'odata_get_all vertaalt Talos-URL naar Mímir',
        is_array($rows[0] ?? null)
            && ($rows[0]['company'] ?? '') === "Van Twist's"
            && ($rows[0]['table'] ?? '') === 'FactureerbareProjectPlanningsRegels'
            && ($rows[0]['filter'] ?? '') === "No eq 'PRJ1'"
            && ($rows[0]['max_age'] ?? null) === 60
            && in_array('Job_No', $rows[0]['select'] ?? [], true),
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );
    $afterCache = glob(__DIR__ . '/../web/cache/odata/*.json') ?: [];
    test_assert('Mímir slaat Talos-filecache over', count($afterCache) === count($beforeCache));

    $companyRows = odata_get_all(buildOdataRootUrl('https://bc.example', 'Sandbox') . 'Companies?$select=Name', [], 30);
    $companyNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery-URL gaat naar Mímir, niet naar BC-host',
        $companyNames === ['Hunter van Twist'],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $buckets = fetchProjectInvoiceBuckets('', [], [], '2026-01-15', 'Hunter van Twist', false, true);
    test_assert(
        'factuurregels zonder baseUrl of BC-auth',
        ($buckets['company_environment_map']['Hunter van Twist'] ?? '') === 'Sandbox'
            && count($buckets['overdue'] ?? []) > 0,
        json_encode([
            'map' => $buckets['company_environment_map'] ?? null,
            'overdue' => count($buckets['overdue'] ?? []),
        ])
    );

    $requests = test_mock_requests();
    $hitBcHost = false;
    $sawMimirUa = false;
    foreach ($requests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), 'bc.example')) {
            $hitBcHost = true;
        }
        if (($request['ua'] ?? '') === 'Talos-MimirClient/1.0' && str_contains((string) ($request['uri'] ?? ''), '/mimir/api/')) {
            $sawMimirUa = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);

    test_reset_mimir_cache();
    $mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir-dup/api';
    $overlapThrew = false;
    try {
        fetchAvailableCompanyContext('', [], []);
    } catch (Exception $error) {
        $overlapThrew = (int) $error->getCode() === 40901;
    }
    test_assert('overlap tussen Mímir-environments blijft een fout', $overlapThrew);

    $mimirApi = '';
    $baseUrl = 'http://127.0.0.1:' . $mockPort;
    $environment = 'Production';
    $auth_list = [
        'Production' => [
            'mode' => 'basic',
            'user' => 'bcuser',
            'pass' => 'bcpass',
        ],
    ];
    $auth = $auth_list['Production'];
    if ($authExistedBefore && !is_string($authBackup)) {
        throw new RuntimeException('Bestaande auth.php kon niet worden gelezen; test wijzigt het bestand niet.');
    }
    $authWritten = true;
    file_put_contents(
        $authPath,
        "<?php\n\$baseUrl = " . var_export($baseUrl, true) . ";\n\$environment = 'Production';\n\$auth_list = " . var_export($auth_list, true) . ";\n\$mimirApi = '';\n"
    );
    test_reset_mimir_cache();
    @unlink($mockLog);

    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    unset($environment);
    test_assert(
        'zonder Mímir blijft lege environment-lijst kvtmdlive_aad',
        getActiveEnvironments() === ['kvtmdlive_aad'],
        json_encode(getActiveEnvironments())
    );
    $threw = false;
    try {
        getAuthForEnvironment('Onbekend');
    } catch (InvalidArgumentException $error) {
        $threw = str_contains($error->getMessage(), 'Unknown environment');
    }
    test_assert('BC-auth ontbreekt blijft exception zonder Mímir', $threw);

    $environment = 'Production';
    $bcRows = odata_get_all($baseUrl . '/Production/ODataV4/Companies?$select=Name', $auth, 30);
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    foreach ($bcRequests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
            $bcHitMimir = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    test_restore_auth_php($authPath, $authExistedBefore, $authBackup, $authWritten);
    @unlink($mockScript);
    @unlink($mockLog);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
