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
$isAdminUser = !empty($_SESSION['user']['admin'])
    || strcasecmp($currentUserEmail, 'localtester@kvt.nl') === 0;
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
    } catch (Exception $e) {
        if (!$isAdminUser) {
            throw $e;
        }
        $salespersonRows = [];
    }

    $buckets = talosPmRemapLegacyManagerCodesInBuckets($buckets, $salespersonRows);

    if ($isAdminUser) {
        $projectManagerAssignments = talosPmLoadHierarchyAssignments();
        $extraManagers = talosPmCollectManagerCandidates(
            $salespersonRows,
            talosPmCollectProjectManagersFromBuckets($buckets)
        );
        foreach ($projectManagerAssignments as $managerName => $children) {
            $extraManagers[] = (string) $managerName;
            foreach ((array) $children as $childName) {
                $extraManagers[] = (string) $childName;
            }
        }

        $allProjectManagers = talosPmUniqueStrings($extraManagers);
        $requestedImpersonation = trim((string) ($_SESSION['pm_impersonation_by_user'][$userKey] ?? ''));
        $activeImpersonationManager = talosPmResolveProjectManagerSelection($allProjectManagers, $requestedImpersonation);
        if ($activeImpersonationManager !== '') {
            $allowedProjectManagers = talosPmGetAllowedProjectManagers(
                $projectManagerAssignments,
                $activeImpersonationManager,
                $allProjectManagers
            );
            if (empty($allowedProjectManagers)) {
                $allowedProjectManagers = [$activeImpersonationManager];
            }

            $buckets = talosPmFilterBucketsByAllowedManagers($buckets, $allowedProjectManagers);
        }
    }

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
