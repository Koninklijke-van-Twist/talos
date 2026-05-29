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
        return [
            'rows' => $buckets['upcoming_month'],
            'title' => LOC('section.upcoming'),
            'window_label' => LOC('section.upcoming_month'),
        ];
    }

    if (!empty($buckets['upcoming_year'])) {
        return [
            'rows' => $buckets['upcoming_year'],
            'title' => LOC('section.upcoming'),
            'window_label' => LOC('section.upcoming_year'),
        ];
    }

    return [
        'rows' => $buckets['all'] ?? [],
        'title' => LOC('section.all_rules'),
        'window_label' => LOC('section.all_rules'),
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
$includeCompanyColumn = $selectedCompany === '';
$currentUserEmail = (string) ($_SESSION['user']['email'] ?? '');
$isAdminUser = !empty($_SESSION['user']['admin'])
    || strcasecmp($currentUserEmail, 'localtester@kvt.nl') === 0;
$canInspectRows = $currentUserEmail === '' || in_array($currentUserEmail, $ictUsers ?? [], true);

/**
 * Page load
 */

header('Content-Type: application/json; charset=UTF-8');

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
    $salespersonRows = [];
    try {
        $salespersonRows = talosPmFetchUserSetupRows($baseUrl, $companyEnvironmentMap, $auth, '');
    } catch (Exception $e) {
        if (!$isAdminUser) {
            throw $e;
        }
        $salespersonRows = [];
    }

    $buckets = talosPmRemapLegacyManagerCodesInBuckets($buckets, $salespersonRows);

    if (!$isAdminUser) {
        $currentUserSetup = talosPmResolveCurrentUserFromUserSetup($salespersonRows, $currentUserEmail);
        if (empty($currentUserSetup['found'])) {
            throw new Exception(LOC('error.user_not_in_usersetup'), 40311);
        }

        $userProjectManager = trim((string) ($currentUserSetup['project_manager'] ?? ''));
        if ($userProjectManager === '') {
            throw new Exception(LOC('error.user_missing_project_manager'), 40312);
        }

        $allProjectManagers = talosPmCollectManagerCandidates(
            $salespersonRows,
            talosPmCollectProjectManagersFromBuckets($buckets)
        );

        $assignments = talosPmLoadHierarchyAssignments();
        $allowedProjectManagers = talosPmGetAllowedProjectManagers($assignments, $userProjectManager, $allProjectManagers);
        if (empty($allowedProjectManagers)) {
            $allowedProjectManagers = [$userProjectManager];
        }

        $buckets = talosPmFilterBucketsByAllowedManagers($buckets, $allowedProjectManagers);
    }

    $displayMap = talosPmBuildProjectManagerDisplayMap(
        $salespersonRows,
        talosPmCollectProjectManagersFromBuckets($buckets)
    );
    $buckets = talosPmApplyDisplayNamesToBuckets($buckets, $displayMap);
    $buckets = talosPmApplyCreatedByDisplayNamesToBuckets($buckets, $salespersonRows);

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

    if (in_array((int) $e->getCode(), [40311, 40312], true)) {
        $payload['error'] = $e->getMessage();
    }

    if ($showOdataErrorDetails) {
        $payload['details'] = $e->getMessage();
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
