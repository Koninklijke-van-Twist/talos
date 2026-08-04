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
if (!isset($_SESSION['pm_impersonation_by_user']) || !is_array($_SESSION['pm_impersonation_by_user'])) {
    $_SESSION['pm_impersonation_by_user'] = [];
}

$postAction = trim((string) ($_POST['action'] ?? ''));

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $postAction !== ''
    && $isAdminUser
) {
    $flashPayload = [];
    if ($postAction === 'pm_admin_save') {
        $selectedManager = trim((string) ($_POST['selected_manager'] ?? ''));
        $selectedChildren = $_POST['assigned_project_managers'] ?? [];
        if (!is_array($selectedChildren)) {
            $selectedChildren = [];
        }

        $assignments = talosPmLoadHierarchyAssignments();
        $assignResult = talosPmAssignChildren($assignments, $selectedManager, $selectedChildren);

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
    } elseif ($postAction === 'pm_admin_impersonate') {
        $selectedManager = trim((string) ($_POST['impersonate_manager'] ?? ''));
        if ($selectedManager === '') {
            $flashPayload = [
                'type' => 'error',
                'message' => LOC('pm_admin.error.missing_manager'),
            ];
        } else {
            $_SESSION['pm_impersonation_by_user'][$userKey] = $selectedManager;
            $flashPayload = [
                'type' => 'success',
                'message' => LOC('pm_admin.flash.impersonation_enabled'),
            ];
        }
    } elseif ($postAction === 'pm_admin_impersonate_clear') {
        unset($_SESSION['pm_impersonation_by_user'][$userKey]);
        $flashPayload = [
            'type' => 'success',
            'message' => LOC('pm_admin.flash.impersonation_cleared'),
        ];
    } else {
        $flashPayload = [
            'type' => 'error',
            'message' => LOC('pm_admin.error.save_failed'),
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
$projectManagerAssignments = [];
$projectManagerInvalidMatrix = [];
$projectManagerDefaultSelection = '';
$projectManagerFilterDefaultSelection = '';
$projectManagerDisplayMap = [];
$salespersonRowsForDisplay = [];
$activeImpersonationManager = '';
$activeImpersonationLabel = '';
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
        $buckets = fetchProjectInvoiceBuckets($baseUrl, $environment, $auth, $today, $selectedCompany, $debugFetchAllRules, $hideSapImports);
        $availableCompanies = $buckets['available_companies'] ?? [];
        $companyEnvironmentMap = $buckets['company_environment_map'] ?? [];
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
        }
    }

    if ($requiresCompanySelection) {
        // Alleen bedrijfslijst ophalen; facturatie-/salesperson-data wacht op keuze.
        $odataError = null;
    } else {

        $projectManagerAssignments = talosPmLoadHierarchyAssignments();

        $singleCompanyScopeMap = talosPmSelectSingleCompanyEnvironmentMap((array) $companyEnvironmentMap, $selectedCompany);

        if ($isAdminUser) {
            $adminUserSetupRows = [];
            try {
                $adminUserSetupRows = talosPmFetchUserSetupRows($baseUrl, $singleCompanyScopeMap, $auth, '');
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

            $requestedImpersonation = trim((string) ($_SESSION['pm_impersonation_by_user'][$userKey] ?? ''));
            $activeImpersonationManager = talosPmResolveProjectManagerSelection($allProjectManagers, $requestedImpersonation);
            if ($activeImpersonationManager === '' && $requestedImpersonation !== '') {
                unset($_SESSION['pm_impersonation_by_user'][$userKey]);
            }

            if ($activeImpersonationManager !== '') {
                $activeImpersonationLabel = (string) ($projectManagerDisplayMap[$activeImpersonationManager] ?? $activeImpersonationManager);
                $projectManagerDefaultSelection = $activeImpersonationManager;
                $allowedProjectManagers = talosPmGetAllowedProjectManagers(
                    $projectManagerAssignments,
                    $activeImpersonationManager,
                    $allProjectManagers
                );
                if (empty($allowedProjectManagers)) {
                    $allowedProjectManagers = [$activeImpersonationManager];
                }

                $buckets = talosPmFilterBucketsByAllowedManagers($buckets, $allowedProjectManagers);
            } else {
                $allowedProjectManagers = $allProjectManagers;
                $projectManagerDefaultSelection = '';
            }

            if ($hasStoredProjectManagerFilter) {
                $projectManagerFilterDefaultSelection = talosPmResolveProjectManagerSelection($allowedProjectManagers, $storedProjectManagerFilter);
            }
        } else {
            $userSetupRows = talosPmFetchUserSetupRows($baseUrl, $singleCompanyScopeMap, $auth, '');
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

            if ($hasStoredProjectManagerFilter) {
                $projectManagerFilterDefaultSelection = talosPmResolveProjectManagerSelection($allowedProjectManagers, $storedProjectManagerFilter);
            } else {
                // Managers with descendants start on "everyone" by default.
                $projectManagerFilterDefaultSelection = count($allowedProjectManagers) > 1 ? '' : $projectManagerDefaultSelection;
            }

            $buckets = talosPmFilterBucketsByAllowedManagers($buckets, $allowedProjectManagers);
        }

        if (empty($projectManagerDisplayMap)) {
            $projectManagerDisplayMap = talosPmBuildProjectManagerDisplayMap([], $allProjectManagers);
        }

        $buckets = talosPmApplyDisplayNamesToBuckets($buckets, $projectManagerDisplayMap);
        $buckets = talosPmApplyCreatedByDisplayNamesToBuckets($buckets, $salespersonRowsForDisplay);
        $projectManagerInvalidMatrix = talosPmBuildInvalidChildrenMatrix($allProjectManagers, $projectManagerAssignments);

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
    $odataError = $e->getMessage();
    $publicErrorCodes = [40901, 40311, 40312];
    $odataErrorPublic = in_array((int) $e->getCode(), $publicErrorCodes, true) ? $e->getMessage() : null;
}