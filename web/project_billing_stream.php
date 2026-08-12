<?php

/**
 * Includes/requires
 */

require_once __DIR__ . '/content/bootstrap.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/content/project_billing.php';
require_once __DIR__ . '/content/project_manager_scope.php';
require_once __DIR__ . '/odata.php';

/**
 * Functies
 */

function selectUpcomingBucket(array $buckets): array
{
    if (!empty($buckets['upcoming_month'])) {
        $rows = $buckets['upcoming_month'];
    } elseif (!empty($buckets['upcoming_year'])) {
        $rows = $buckets['upcoming_year'];
    } else {
        $rows = $buckets['all'] ?? [];
    }

    return [
        'rows' => $rows,
        'title' => LOC('section.upcoming'),
        'window_label' => '',
    ];
}

/**
 * Variabelen
 */

$today = date('Y-m-d');
$debugFetchAllRules = isset($_GET['debug_all_rules']) && (string) $_GET['debug_all_rules'] === '1';
$showOdataErrorDetails = isset($_GET['debug_odata']) && (string) $_GET['debug_odata'] === '1';
$hideSapImports = !isset($_GET['hide_sap_imports']) || (string) $_GET['hide_sap_imports'] !== '0';
$requestedCompany = trim((string) ($_GET['company'] ?? ''));
$selectedCompany = $requestedCompany === '__all__' ? '' : $requestedCompany;
if ($selectedCompany === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => LOC('filter.company_required_body'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$includeCompanyColumn = false;
$currentUserEmail = (string) ($_SESSION['user']['email'] ?? '');
$canInspectRows = $currentUserEmail === '' || in_array($currentUserEmail, $ictUsers ?? [], true);
$userKey = (string) ($_SESSION['user']['email'] ?? 'anonymous');

/**
 * Page load
 */

header('Content-Type: application/json; charset=UTF-8');

$streamAction = trim((string) ($_GET['action'] ?? ''));
if ($streamAction === 'save_project_manager_filter') {
    if (!isset($_SESSION['selected_project_manager_filter_by_user']) || !is_array($_SESSION['selected_project_manager_filter_by_user'])) {
        $_SESSION['selected_project_manager_filter_by_user'] = [];
    }

    $value = trim((string) ($_GET['project_manager'] ?? ''));
    if ($value === '' || $value === '__all__') {
        $_SESSION['selected_project_manager_filter_by_user'][$userKey] = '';
    } else {
        $_SESSION['selected_project_manager_filter_by_user'][$userKey] = $value;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $buckets = fetchProjectInvoiceBuckets(
        $baseUrl,
        $environment,
        $auth,
        $today,
        $selectedCompany,
        $debugFetchAllRules,
        $hideSapImports
    );

    $companyEnvironmentMap = (array) ($buckets['company_environment_map'] ?? []);
    $singleCompanyScopeMap = talosPmSelectSingleCompanyEnvironmentMap($companyEnvironmentMap, $selectedCompany);
    $salespersonRows = [];
    try {
        $salespersonRows = talosPmFetchUserSetupRows($baseUrl, $singleCompanyScopeMap, $auth, '');
    } catch (Exception $ignored) {
        $salespersonRows = [];
    }

    $buckets = talosPmRemapLegacyManagerCodesInBuckets($buckets, $salespersonRows);

    $displayMap = talosPmBuildProjectManagerDisplayMap(
        $salespersonRows,
        talosPmCollectProjectManagersFromBuckets($buckets)
    );
    $buckets = talosPmApplyDisplayNamesToBuckets($buckets, $displayMap);
    $buckets = talosPmApplyCreatedByDisplayNamesToBuckets($buckets, $salespersonRows);
    if (function_exists('talosFilterBucketsByAllowedDepartments')) {
        $buckets = talosFilterBucketsByAllowedDepartments($buckets, talosCurrentUserAllowedDepartments());
    }

    $upcoming = selectUpcomingBucket($buckets);
    $pendingRows = array_values($buckets['overdue'] ?? []);
    $upcomingRows = array_values($upcoming['rows'] ?? []);

    $pendingRowHtml = [];
    foreach ($pendingRows as $row) {
        $pendingRowHtml[] = renderInvoiceTableRow($row, true, $includeCompanyColumn, $canInspectRows);
    }

    $upcomingRowHtml = [];
    foreach ($upcomingRows as $row) {
        $upcomingRowHtml[] = renderInvoiceTableRow($row, false, $includeCompanyColumn, $canInspectRows);
    }

    echo json_encode([
        'ok' => true,
        'page' => (int) ($buckets['page'] ?? 1),
        'chunk_size' => (int) ($buckets['chunk_size'] ?? 5),
        'is_partial' => !empty($buckets['is_partial']),
        'calls_used' => (int) ($buckets['calls_used'] ?? 0),
        'max_calls' => (int) ($buckets['max_calls'] ?? 0),
        'pending_rows' => $pendingRows,
        'upcoming_rows' => $upcomingRows,
        'pending_row_html' => $pendingRowHtml,
        'upcoming_row_html' => $upcomingRowHtml,
        'upcoming_title' => (string) ($upcoming['title'] ?? ''),
        'upcoming_window_label' => (string) ($upcoming['window_label'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $payload = [
        'ok' => false,
        'error' => LOC('error.odata_failed'),
    ];

    if ($showOdataErrorDetails) {
        $payload['details'] = $e->getMessage();
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
