<?php

/**
 * Nightly cache warmer.
 *
 * Called via GET (with API key) around 02:00. Fetches all project-billing and
 * SalesPersonCard data so daytime page loads can read from the 23h OData cache.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/content/project_billing.php';
require_once __DIR__ . '/content/project_manager_scope.php';
require_once __DIR__ . '/odata.php';

@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=UTF-8');

$incomingKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '');
if (!validateApiKey($incomingKey, $apiKeys)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$today = date('Y-m-d');
$startedAt = microtime(true);

$activeEnvironments = function_exists('talosNormalizeEnvironmentList')
    ? talosNormalizeEnvironmentList($environment)
    : (is_array($environment) ? $environment : [trim((string) $environment)]);

$primaryEnvironment = (string) ($activeEnvironments[0] ?? '');
$primaryAuth = function_exists('getAuthForEnvironment') && $primaryEnvironment !== ''
    ? getAuthForEnvironment($primaryEnvironment)
    : $auth;

$result = [
    'ok' => true,
    'today' => $today,
    'cache_ttl_seconds' => PROJECT_BILLING_CACHE_TTL_SECONDS,
    'environments' => $activeEnvironments,
    'companies' => [],
    'totals' => [
        'companies' => 0,
        'overdue_rows' => 0,
        'future_rows' => 0,
        'salesperson_rows' => 0,
        'errors' => 0,
    ],
    'errors' => [],
];

try {
    $companyContext = fetchAvailableCompanyContext((string) $baseUrl, $activeEnvironments, $primaryAuth);
    $availableCompanies = (array) ($companyContext['available_companies'] ?? []);
    $companyEnvironmentMap = (array) ($companyContext['company_environment_map'] ?? []);

    if (function_exists('setCompanyEnvironmentMap')) {
        setCompanyEnvironmentMap($companyEnvironmentMap);
    }

    $result['totals']['companies'] = count($availableCompanies);

    foreach ($availableCompanies as $companyName) {
        $company = trim((string) $companyName);
        if ($company === '') {
            continue;
        }

        $companyResult = [
            'company' => $company,
            'environment' => (string) ($companyEnvironmentMap[$company] ?? ''),
            'ok' => true,
            'overdue_rows' => 0,
            'future_rows' => 0,
            'salesperson_rows' => 0,
            'error' => null,
        ];

        try {
            $buckets = fetchProjectInvoiceBuckets(
                (string) $baseUrl,
                $activeEnvironments,
                $primaryAuth,
                $today,
                $company,
                false,
                true
            );

            $overdueCount = count((array) ($buckets['overdue'] ?? []));
            $futureCount = count((array) ($buckets['upcoming_month'] ?? []));
            $companyResult['overdue_rows'] = $overdueCount;
            $companyResult['future_rows'] = $futureCount;
            $result['totals']['overdue_rows'] += $overdueCount;
            $result['totals']['future_rows'] += $futureCount;

            $scopeMap = [$company => (string) ($companyEnvironmentMap[$company] ?? '')];
            $salespersonRows = talosPmFetchUserSetupRows(
                (string) $baseUrl,
                $scopeMap,
                $primaryAuth,
                ''
            );
            $salespersonCount = count($salespersonRows);
            $companyResult['salesperson_rows'] = $salespersonCount;
            $result['totals']['salesperson_rows'] += $salespersonCount;
        } catch (Throwable $e) {
            $companyResult['ok'] = false;
            $companyResult['error'] = $e->getMessage();
            $result['totals']['errors']++;
            $result['errors'][] = $company . ': ' . $e->getMessage();
            $result['ok'] = false;
        }

        $result['companies'][] = $companyResult;
    }
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['errors'][] = $e->getMessage();
    $result['totals']['errors']++;
    http_response_code(502);
}

$result['duration_seconds'] = round(microtime(true) - $startedAt, 3);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
