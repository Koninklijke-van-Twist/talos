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

if (!isset($_SESSION['pm_admin_flash_by_user']) || !is_array($_SESSION['pm_admin_flash_by_user'])) {
    $_SESSION['pm_admin_flash_by_user'] = [];
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && (string) $_POST['action'] === 'pm_admin_save'
    && $isAdminUser
) {
    $selectedManager = trim((string) ($_POST['selected_manager'] ?? ''));
    $selectedChildren = $_POST['assigned_project_managers'] ?? [];
    if (!is_array($selectedChildren)) {
        $selectedChildren = [];
    }

    $assignments = talosPmLoadHierarchyAssignments();
    $assignResult = talosPmAssignChildren($assignments, $selectedManager, $selectedChildren);

    $flashPayload = [];
    if (!empty($assignResult['ok'])) {
        $saveOk = talosPmSaveHierarchyAssignments(
            (array) ($assignResult['assignments'] ?? []),
            $currentUserEmail
        );

        if ($saveOk) {
            $flashPayload = [
                'type' => 'success',
                'message' => LOC('pm_admin.flash.saved'),
            ];
        } else {
            $flashPayload = [
                'type' => 'error',
                'message' => LOC('pm_admin.error.save_failed'),
            ];
        }
    } else {
        $errorKey = (string) ($assignResult['error_key'] ?? 'pm_admin.error.save_failed');
        $errorArgs = (array) ($assignResult['error_args'] ?? []);
        $flashPayload = [
            'type' => 'error',
            'message' => LOC($errorKey, ...$errorArgs),
        ];
    }

    $_SESSION['pm_admin_flash_by_user'][$userKey] = $flashPayload;

    $redirectUrl = (string) ($_SERVER['REQUEST_URI'] ?? 'index.php');
    header('Location: ' . $redirectUrl);
    exit;
}

$adminFlash = $_SESSION['pm_admin_flash_by_user'][$userKey] ?? null;
unset($_SESSION['pm_admin_flash_by_user'][$userKey]);

if (!isset($_SESSION['selected_company_by_user']) || !is_array($_SESSION['selected_company_by_user'])) {
    $_SESSION['selected_company_by_user'] = [];
}
if (!isset($_SESSION['selected_company_environment_by_user']) || !is_array($_SESSION['selected_company_environment_by_user'])) {
    $_SESSION['selected_company_environment_by_user'] = [];
}

$requestedCompany = trim((string) ($_GET['company'] ?? ''));
$isAllCompaniesSelection = $requestedCompany === '__all__';
$selectedCompany = $requestedCompany !== ''
    ? $requestedCompany
    : (string) ($_SESSION['selected_company_by_user'][$userKey] ?? '');

if ($isAllCompaniesSelection) {
    $selectedCompany = '';
}

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
$projectManagerAssignments = [];
$projectManagerInvalidMatrix = [];
$projectManagerDefaultSelection = '';
$projectManagerDisplayMap = [];
$salespersonRowsForDisplay = [];

/**
 * Page load
 */

try {
    $buckets = fetchProjectInvoiceBuckets($baseUrl, $environment, $auth, $today, $selectedCompany, $debugFetchAllRules, $hideSapImports);
    $availableCompanies = $buckets['available_companies'] ?? [];
    $companyEnvironmentMap = $buckets['company_environment_map'] ?? [];
    if (function_exists('setCompanyEnvironmentMap') && is_array($companyEnvironmentMap)) {
        setCompanyEnvironmentMap($companyEnvironmentMap);
    }

    $needsRefetch = false;
    if (
        $selectedCompany !== ''
        && !empty($availableCompanies)
        && !in_array($selectedCompany, $availableCompanies, true)
    ) {
        $selectedCompany = (string) $availableCompanies[0];
        $needsRefetch = true;
    }

    if ($needsRefetch) {
        $buckets = fetchProjectInvoiceBuckets($baseUrl, $environment, $auth, $today, $selectedCompany, $debugFetchAllRules, $hideSapImports);
        $availableCompanies = $buckets['available_companies'] ?? [];
        $companyEnvironmentMap = $buckets['company_environment_map'] ?? [];
        if (function_exists('setCompanyEnvironmentMap') && is_array($companyEnvironmentMap)) {
            setCompanyEnvironmentMap($companyEnvironmentMap);
        }
    }

    $projectManagerAssignments = talosPmLoadHierarchyAssignments();

    if ($isAdminUser) {
        $adminUserSetupRows = [];
        try {
            // Voor admin-beheer altijd alle bedrijven meenemen, los van huidige company-filter.
            $adminUserSetupRows = talosPmFetchUserSetupRows($baseUrl, (array) $companyEnvironmentMap, $auth, '');
        } catch (Exception $ignored) {
            $adminUserSetupRows = [];
        }
        $salespersonRowsForDisplay = $adminUserSetupRows;

        $buckets = talosPmRemapLegacyManagerCodesInBuckets($buckets, $adminUserSetupRows);

        $extraManagers = talosPmCollectManagerCandidates(
            $adminUserSetupRows,
            talosPmCollectProjectManagersFromBuckets($buckets)
        );
        foreach ($projectManagerAssignments as $managerName => $children) {
            $extraManagers[] = (string) $managerName;
            foreach ((array) $children as $childName) {
                $extraManagers[] = (string) $childName;
            }
        }

        $allProjectManagers = talosPmUniqueStrings($extraManagers);
        $projectManagerDisplayMap = talosPmBuildProjectManagerDisplayMap($adminUserSetupRows, $allProjectManagers);
        $allowedProjectManagers = $allProjectManagers;
        $projectManagerDefaultSelection = '';
    } else {
        $userSetupRows = talosPmFetchUserSetupRows($baseUrl, (array) $companyEnvironmentMap, $auth, '');
        $currentUserSetup = talosPmResolveCurrentUserFromUserSetup($userSetupRows, $currentUserEmail);

        if (empty($currentUserSetup['found'])) {
            throw new Exception(LOC('error.user_not_in_usersetup'), 40311);
        }

        $projectManagerDefaultSelection = trim((string) ($currentUserSetup['project_manager'] ?? ''));
        if ($projectManagerDefaultSelection === '') {
            throw new Exception(LOC('error.user_missing_project_manager'), 40312);
        }

        $buckets = talosPmRemapLegacyManagerCodesInBuckets($buckets, $userSetupRows);
        $salespersonRowsForDisplay = $userSetupRows;

        $allProjectManagers = talosPmCollectManagerCandidates(
            $userSetupRows,
            talosPmCollectProjectManagersFromBuckets($buckets)
        );
        $projectManagerDisplayMap = talosPmBuildProjectManagerDisplayMap($userSetupRows, $allProjectManagers);

        $allowedProjectManagers = talosPmGetAllowedProjectManagers(
            $projectManagerAssignments,
            $projectManagerDefaultSelection,
            $allProjectManagers
        );

        if (empty($allowedProjectManagers)) {
            $allowedProjectManagers = [$projectManagerDefaultSelection];
        }

        $buckets = talosPmFilterBucketsByAllowedManagers($buckets, $allowedProjectManagers);
    }

    if (empty($projectManagerDisplayMap)) {
        $projectManagerDisplayMap = talosPmBuildProjectManagerDisplayMap([], $allProjectManagers);
    }

    $buckets = talosPmApplyDisplayNamesToBuckets($buckets, $projectManagerDisplayMap);
    $buckets = talosPmApplyCreatedByDisplayNamesToBuckets($buckets, $salespersonRowsForDisplay);
    $projectManagerInvalidMatrix = talosPmBuildInvalidChildrenMatrix($allProjectManagers, $projectManagerAssignments);

    if ($selectedCompany !== '') {
        $_SESSION['selected_company_by_user'][$userKey] = $selectedCompany;
        $selectedCompanyEnvironment = (string) ($buckets['selected_company_environment'] ?? '');
        if ($selectedCompanyEnvironment === '' && function_exists('getEnvironmentForCompany')) {
            $selectedCompanyEnvironment = (string) (getEnvironmentForCompany($selectedCompany) ?? '');
        }
        $_SESSION['selected_company_environment_by_user'][$userKey] = $selectedCompanyEnvironment;
    } else {
        $_SESSION['selected_company_by_user'][$userKey] = '__all__';
        $_SESSION['selected_company_environment_by_user'][$userKey] = '__all__';
    }

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
        $upcomingWindowLabel = LOC('section.upcoming_month');
        $upcomingSectionTitle = LOC('section.upcoming');
    } elseif (!empty($buckets['upcoming_year'])) {
        $upcomingInvoiceLines = $buckets['upcoming_year'];
        $upcomingWindowLabel = LOC('section.upcoming_year');
        $upcomingSectionTitle = LOC('section.upcoming');
    } else {
        $upcomingInvoiceLines = $buckets['all'];
        $upcomingWindowLabel = LOC('section.all_rules');
        $upcomingSectionTitle = LOC('section.all_rules');
    }

    if (!empty($upcomingInvoiceLines) && $upcomingWindowLabel !== LOC('section.upcoming_month')) {
        $batchMinDate = '';
        $batchMaxDate = '';
        foreach ($upcomingInvoiceLines as $line) {
            $dateValue = (string) ($line['Planning_Date'] ?? '');
            if ($dateValue === '') {
                continue;
            }

            if ($batchMinDate === '' || $dateValue < $batchMinDate) {
                $batchMinDate = $dateValue;
            }
            if ($batchMaxDate === '' || $dateValue > $batchMaxDate) {
                $batchMaxDate = $dateValue;
            }
        }

        if ($batchMinDate !== '' && $batchMaxDate !== '') {
            $upcomingWindowLabel = LOC(
                'section.week_batch_window',
                formatDate($batchMinDate),
                formatDate($batchMaxDate)
            );
        }
    }

    $odataError = null;
} catch (Exception $e) {
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
    $odataError = $e->getMessage();
    $publicErrorCodes = [40901, 40311, 40312];
    $odataErrorPublic = in_array((int) $e->getCode(), $publicErrorCodes, true) ? $e->getMessage() : null;
}