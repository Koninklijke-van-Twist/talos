<?php

/**
 * Hourly incremental billing snapshot refresh.
 *
 * Uses SystemModifiedAt deltas when available; otherwise a coalesced full-window
 * refresh. Concurrent callers for the same company share one BC pull.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/content/project_billing.php';
require_once __DIR__ . '/content/billing_snapshot.php';
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
    'environments' => $activeEnvironments,
    'companies' => [],
    'totals' => [
        'companies' => 0,
        'fetched' => 0,
        'coalesced' => 0,
        'upserted' => 0,
        'removed' => 0,
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

    $refreshContext = [
        'baseUrl' => (string) $baseUrl,
        'auth' => $primaryAuth,
        'today' => $today,
        'hideSapImports' => true,
        'companyEnvironmentMap' => $companyEnvironmentMap,
        'activeEnvironments' => $activeEnvironments,
    ];

    foreach ($availableCompanies as $companyName) {
        $company = trim((string) $companyName);
        if ($company === '') {
            continue;
        }

        $companyResult = [
            'company' => $company,
            'environment' => (string) ($companyEnvironmentMap[$company] ?? ''),
            'ok' => true,
            'pending' => false,
            'coalesced' => false,
            'fetched' => false,
            'strategy' => null,
            'version' => 0,
            'upserted' => 0,
            'removed' => 0,
            'error' => null,
        ];

        try {
            $refresh = talosBillingCoalescedRefresh($company, 'hourly', $refreshContext);
            $companyResult['ok'] = !empty($refresh['ok']);
            $companyResult['pending'] = !empty($refresh['pending']);
            $companyResult['coalesced'] = !empty($refresh['coalesced']);
            $companyResult['fetched'] = !empty($refresh['fetched']);
            $companyResult['strategy'] = $refresh['strategy'] ?? null;
            $companyResult['version'] = (int) ($refresh['version'] ?? 0);
            $companyResult['upserted'] = (int) ($refresh['upserted'] ?? 0);
            $companyResult['removed'] = (int) ($refresh['removed'] ?? 0);
            $companyResult['error'] = $refresh['error'] ?? null;

            if (!empty($refresh['fetched'])) {
                $result['totals']['fetched']++;
            }
            if (!empty($refresh['coalesced'])) {
                $result['totals']['coalesced']++;
            }
            $result['totals']['upserted'] += $companyResult['upserted'];
            $result['totals']['removed'] += $companyResult['removed'];

            if (empty($refresh['ok'])) {
                $result['ok'] = false;
                $result['totals']['errors']++;
                $result['errors'][] = $company . ': ' . (string) ($refresh['error'] ?? 'refresh failed');
            }
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
