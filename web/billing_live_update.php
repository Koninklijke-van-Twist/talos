<?php

/**
 * Silent live billing update endpoint.
 * Triggers a coalesced company refresh (non-blocking) and returns dept-filtered
 * row patches since the client's snapshot version.
 */

declare(strict_types=1);

require_once __DIR__ . '/content/bootstrap.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/content/project_billing.php';
require_once __DIR__ . '/content/billing_snapshot.php';
require_once __DIR__ . '/content/department_access.php';
require_once __DIR__ . '/content/project_manager_scope.php';
require_once __DIR__ . '/odata.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$company = trim((string) ($_GET['company'] ?? ''));
$sinceVersion = max(0, (int) ($_GET['since_version'] ?? 0));
$today = date('Y-m-d');
$hideSapImports = !isset($_GET['hide_sap_imports']) || (string) $_GET['hide_sap_imports'] !== '0';
$includeCompanyColumn = false;

if ($company === '' || $company === '__all__') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'company required'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $activeEnvironments = function_exists('talosNormalizeEnvironmentList')
        ? talosNormalizeEnvironmentList($environment)
        : (is_array($environment) ? $environment : [trim((string) $environment)]);

    $primaryEnvironment = (string) ($activeEnvironments[0] ?? '');
    $primaryAuth = function_exists('getAuthForEnvironment') && $primaryEnvironment !== ''
        ? getAuthForEnvironment($primaryEnvironment)
        : $auth;

    $companyContext = fetchAvailableCompanyContext((string) $baseUrl, $activeEnvironments, $primaryAuth);
    $companyEnvironmentMap = (array) ($companyContext['company_environment_map'] ?? []);
    if (function_exists('setCompanyEnvironmentMap')) {
        setCompanyEnvironmentMap($companyEnvironmentMap);
    }

    if (!isset($companyEnvironmentMap[$company])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Unknown company'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $refresh = talosBillingCoalescedRefresh($company, 'live', [
        'baseUrl' => (string) $baseUrl,
        'auth' => $primaryAuth,
        'today' => $today,
        'hideSapImports' => $hideSapImports,
        'companyEnvironmentMap' => $companyEnvironmentMap,
        'activeEnvironments' => $activeEnvironments,
    ], [
        'non_blocking' => true,
    ]);

    $allowedDepartments = function_exists('talosCurrentUserAllowedDepartments')
        ? talosCurrentUserAllowedDepartments()
        : null;

    $patches = talosBillingLivePatches($company, $sinceVersion, $allowedDepartments, $today);

    // Apply salesperson display names lightly for upsert HTML.
    $upserted = $patches['upserted'];
    if ($upserted !== []) {
        try {
            $scopeMap = [$company => (string) ($companyEnvironmentMap[$company] ?? '')];
            $salespersonRows = talosPmFetchUserSetupRows((string) $baseUrl, $scopeMap, $primaryAuth, '');
            $overdueRows = [];
            $upcomingRows = [];
            foreach ($upserted as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (($row['_bucket'] ?? '') === 'overdue') {
                    $overdueRows[] = $row;
                } else {
                    $upcomingRows[] = $row;
                }
            }
            $buckets = [
                'overdue' => $overdueRows,
                'upcoming_month' => $upcomingRows,
                'upcoming_year' => [],
                'all' => [],
            ];
            $displayMap = talosPmBuildProjectManagerDisplayMap(
                $salespersonRows,
                talosPmCollectProjectManagersFromBuckets($buckets)
            );
            $buckets = talosPmApplyDisplayNamesToBuckets($buckets, $displayMap);
            $buckets = talosPmApplyCreatedByDisplayNamesToBuckets($buckets, $salespersonRows);
            $upserted = array_merge($buckets['overdue'], $buckets['upcoming_month']);
            foreach ($upserted as &$row) {
                $planningDate = trim((string) ($row['Planning_Date'] ?? ''));
                $row['_bucket'] = ($planningDate !== '' && $planningDate <= $today) ? 'overdue' : 'upcoming';
            }
            unset($row);
        } catch (Throwable $ignored) {
            // Keep raw upserted rows if salesperson enrich fails.
        }
    }

    $upsertPayload = [];
    foreach ($upserted as $row) {
        if (!is_array($row)) {
            continue;
        }
        $bucket = (string) ($row['_bucket'] ?? 'upcoming');
        $isOverdue = $bucket === 'overdue';
        $upsertPayload[] = [
            'key' => talosBillingRowKey($row),
            'bucket' => $isOverdue ? 'overdue' : 'upcoming',
            'html' => renderInvoiceTableRow($row, $isOverdue, $includeCompanyColumn, false),
            'line_amount' => (float) ($row['Line_Amount'] ?? 0),
            'cost_center_code' => (string) ($row['_cost_center_code'] ?? ''),
            'project_manager' => (string) ($row['_project_manager'] ?? ''),
            'created_by' => (string) ($row['_created_by_display'] ?? extractAccountManagerFromUserId((string) ($row['User_ID'] ?? ''))),
            'status' => (string) (($row['KVT_Status_Work_Order'] ?? '') !== '' ? ($row['KVT_Status_Work_Order'] ?? '') : ($row['Status'] ?? 'Open')),
        ];
    }

    echo json_encode([
        'ok' => true,
        'pending' => !empty($refresh['pending']),
        'coalesced' => !empty($refresh['coalesced']),
        'fetched' => !empty($refresh['fetched']),
        'strategy' => $refresh['strategy'] ?? null,
        'version' => (int) ($patches['version'] ?? ($refresh['version'] ?? 0)),
        'upserted' => $upsertPayload,
        'removed' => array_values($patches['removed']),
        'refresh_ok' => !empty($refresh['ok']),
        'refresh_error' => $refresh['error'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
