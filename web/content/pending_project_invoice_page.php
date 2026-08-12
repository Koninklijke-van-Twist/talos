<?php

/**
 * Variabelen
 */

$today = date('Y-m-d');
$showOdataErrorDetails = isset($_GET['debug_odata']) && (string) $_GET['debug_odata'] === '1';
$debugFetchAllRules = isset($_GET['debug_all_rules']) && (string) $_GET['debug_all_rules'] === '1';
$hideSapImports = !isset($_GET['hide_sap_imports']) || (string) $_GET['hide_sap_imports'] !== '0';
$userKey = (string) ($_SESSION['user']['email'] ?? 'anonymous');
$currentUserEmail = (string) ($_SESSION['user']['email'] ?? '');
$isAdminUser = !empty($_SESSION['user']['admin'])
    || strcasecmp($currentUserEmail, 'localtester@kvt.nl') === 0;

if (!isset($_SESSION['selected_company_by_user']) || !is_array($_SESSION['selected_company_by_user'])) {
    $_SESSION['selected_company_by_user'] = [];
}
if (!isset($_SESSION['selected_company_environment_by_user']) || !is_array($_SESSION['selected_company_environment_by_user'])) {
    $_SESSION['selected_company_environment_by_user'] = [];
}
if (!isset($_SESSION['selected_project_manager_filter_by_user']) || !is_array($_SESSION['selected_project_manager_filter_by_user'])) {
    $_SESSION['selected_project_manager_filter_by_user'] = [];
}

$requestedCompany = trim((string) ($_GET['company'] ?? ''));
$storedCompany = trim((string) ($_SESSION['selected_company_by_user'][$userKey] ?? ''));
if ($storedCompany === '__all__') {
    $storedCompany = '';
    unset($_SESSION['selected_company_by_user'][$userKey]);
    unset($_SESSION['selected_company_environment_by_user'][$userKey]);
}

$selectedCompany = $requestedCompany !== ''
    ? $requestedCompany
    : $storedCompany;

if ($selectedCompany === '__all__') {
    $selectedCompany = '';
}

$requiresCompanySelection = ($selectedCompany === '');

$odataErrorPublic = null;
$currentUserSetup = [
    'found' => false,
    'email' => $currentUserEmail,
    'user_id' => '',
    'project_manager' => '',
    'project_manager_name' => '',
    'environment' => '',
    'row' => null,
];
$allowedProjectManagers = [];
$allProjectManagers = [];
$projectManagerDefaultSelection = '';
$projectManagerFilterDefaultSelection = '';
$projectManagerDisplayMap = [];
$salespersonRowsForDisplay = [];
$availableCompanies = [];
$pendingInvoiceLines = [];
$upcomingInvoiceLines = [];
$debugCompanyResults = [];
$allLinesWithoutPlanningDate = 0;
$isPartialResult = false;
$chunkSize = 5;
$currentPage = 1;
$callsUsed = 0;
$maxCalls = 0;
$upcomingWindowLabel = '';
$upcomingSectionTitle = '';
$odataError = null;
$billingSnapshotVersion = 0;

$hasStoredProjectManagerFilter = array_key_exists($userKey, $_SESSION['selected_project_manager_filter_by_user']);
$storedProjectManagerFilter = trim((string) ($_SESSION['selected_project_manager_filter_by_user'][$userKey] ?? ''));

/**
 * Page load
 */

try {
    if ($requiresCompanySelection) {
        $activeEnvironments = function_exists('talosNormalizeEnvironmentList')
            ? talosNormalizeEnvironmentList($environment)
            : (is_array($environment) ? $environment : [trim((string) $environment)]);

        $companyContext = fetchAvailableCompanyContext($baseUrl, $activeEnvironments, $auth);
        $availableCompanies = $companyContext['available_companies'] ?? [];
        $companyEnvironmentMap = $companyContext['company_environment_map'] ?? [];
        if (function_exists('setCompanyEnvironmentMap') && is_array($companyEnvironmentMap)) {
            setCompanyEnvironmentMap($companyEnvironmentMap);
        }
    } else {
        $activeEnvironments = function_exists('talosNormalizeEnvironmentList')
            ? talosNormalizeEnvironmentList($environment)
            : (is_array($environment) ? $environment : [trim((string) $environment)]);

        $companyContext = fetchAvailableCompanyContext($baseUrl, $activeEnvironments, $auth);
        $availableCompanies = $companyContext['available_companies'] ?? [];
        $companyEnvironmentMap = $companyContext['company_environment_map'] ?? [];
        if (function_exists('setCompanyEnvironmentMap') && is_array($companyEnvironmentMap)) {
            setCompanyEnvironmentMap($companyEnvironmentMap);
        }

        if (
            $selectedCompany !== ''
            && !empty($availableCompanies)
            && !in_array($selectedCompany, $availableCompanies, true)
        ) {
            $selectedCompany = '';
            $requiresCompanySelection = true;
            unset($_SESSION['selected_company_by_user'][$userKey]);
            unset($_SESSION['selected_company_environment_by_user'][$userKey]);
        } else {
            $buckets = null;
            if (function_exists('talosBillingBucketsFromSnapshot')) {
                $buckets = talosBillingBucketsFromSnapshot(
                    $selectedCompany,
                    $today,
                    (array) $availableCompanies,
                    (array) $companyEnvironmentMap
                );
            }

            if ($buckets === null) {
                $buckets = fetchProjectInvoiceBuckets(
                    $baseUrl,
                    $environment,
                    $auth,
                    $today,
                    $selectedCompany,
                    $debugFetchAllRules,
                    $hideSapImports
                );
                $availableCompanies = $buckets['available_companies'] ?? $availableCompanies;
                $companyEnvironmentMap = $buckets['company_environment_map'] ?? $companyEnvironmentMap;
                if (function_exists('setCompanyEnvironmentMap') && is_array($companyEnvironmentMap)) {
                    setCompanyEnvironmentMap($companyEnvironmentMap);
                }
            }

            $billingSnapshotVersion = (int) ($buckets['snapshot_version'] ?? 0);
            if ($billingSnapshotVersion <= 0 && function_exists('talosBillingLoadSnapshot')) {
                $snap = talosBillingLoadSnapshot($selectedCompany);
                $billingSnapshotVersion = (int) ($snap['version'] ?? 0);
            }
        }
    }

    if ($requiresCompanySelection) {
        // Alleen bedrijfslijst ophalen; facturatie-/salesperson-data wacht op keuze.
        $odataError = null;
    } else {
        $singleCompanyScopeMap = talosPmSelectSingleCompanyEnvironmentMap((array) $companyEnvironmentMap, $selectedCompany);

        $salespersonRows = [];
        try {
            $salespersonRows = talosPmFetchUserSetupRows($baseUrl, $singleCompanyScopeMap, $auth, '');
        } catch (Exception $ignored) {
            $salespersonRows = [];
        }

        $salespersonRowsForDisplay = $salespersonRows;
        $buckets = talosPmRemapLegacyManagerCodesInBuckets($buckets, $salespersonRows);

        $allProjectManagers = talosPmCollectManagerCandidates(
            $salespersonRows,
            talosPmCollectProjectManagersFromBuckets($buckets)
        );
        $projectManagerDisplayMap = talosPmBuildProjectManagerDisplayMap($salespersonRows, $allProjectManagers);
        $allowedProjectManagers = $allProjectManagers;

        if ($hasStoredProjectManagerFilter) {
            $projectManagerFilterDefaultSelection = talosPmResolveProjectManagerSelection(
                $allowedProjectManagers,
                $storedProjectManagerFilter
            );
        }

        if (empty($projectManagerDisplayMap)) {
            $projectManagerDisplayMap = talosPmBuildProjectManagerDisplayMap([], $allProjectManagers);
        }

        $buckets = talosPmApplyDisplayNamesToBuckets($buckets, $projectManagerDisplayMap);
        $buckets = talosPmApplyCreatedByDisplayNamesToBuckets($buckets, $salespersonRowsForDisplay);
        if (function_exists('talosFilterBucketsByAllowedDepartments')) {
            $buckets = talosFilterBucketsByAllowedDepartments($buckets, talosCurrentUserAllowedDepartments());
        }

        $_SESSION['selected_company_by_user'][$userKey] = $selectedCompany;
        $selectedCompanyEnvironment = (string) ($buckets['selected_company_environment'] ?? '');
        if ($selectedCompanyEnvironment === '' && function_exists('getEnvironmentForCompany')) {
            $selectedCompanyEnvironment = (string) (getEnvironmentForCompany($selectedCompany) ?? '');
        }
        $_SESSION['selected_company_environment_by_user'][$userKey] = $selectedCompanyEnvironment;

        $debugCompanyResults = $buckets['debug_company_results'] ?? [];
        $allLinesWithoutPlanningDate = (int) ($buckets['all_without_planning_date'] ?? 0);
        $isPartialResult = !empty($buckets['is_partial']);
        $chunkSize = (int) ($buckets['chunk_size'] ?? 5);
        $currentPage = (int) ($buckets['page'] ?? 1);
        $callsUsed = (int) ($buckets['calls_used'] ?? 0);
        $maxCalls = (int) ($buckets['max_calls'] ?? 0);

        $pendingInvoiceLines = $buckets['overdue'];

        if (!empty($buckets['upcoming_month'])) {
            $upcomingInvoiceLines = $buckets['upcoming_month'];
        } elseif (!empty($buckets['upcoming_year'])) {
            $upcomingInvoiceLines = $buckets['upcoming_year'];
        } else {
            $upcomingInvoiceLines = $buckets['all'];
        }

        $upcomingSectionTitle = LOC('section.upcoming');
        $upcomingWindowLabel = '';

        $odataError = null;
    }
} catch (Exception $e) {
    $pendingInvoiceLines = [];
    $upcomingInvoiceLines = [];
    $debugCompanyResults = [];
    $allLinesWithoutPlanningDate = 0;
    $isPartialResult = false;
    $chunkSize = 5;
    $currentPage = 1;
    $callsUsed = 0;
    $maxCalls = 0;
    $upcomingWindowLabel = '';
    $upcomingSectionTitle = '';
    $billingSnapshotVersion = 0;
    $odataError = $e->getMessage();
    $publicErrorCodes = [40901];
    $odataErrorPublic = in_array((int) $e->getCode(), $publicErrorCodes, true) ? $e->getMessage() : null;
}
