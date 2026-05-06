<?php

/**
 * Variabelen
 */

$today = date('Y-m-d');
$showOdataErrorDetails = isset($_GET['debug_odata']) && (string) $_GET['debug_odata'] === '1';
$debugFetchAllRules = isset($_GET['debug_all_rules']) && (string) $_GET['debug_all_rules'] === '1';
$hideSapImports = !isset($_GET['hide_sap_imports']) || (string) $_GET['hide_sap_imports'] !== '0';
$userKey = (string) ($_SESSION['user']['email'] ?? 'anonymous');
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

    if (!empty($buckets['upcoming_week'])) {
        $upcomingInvoiceLines = $buckets['upcoming_week'];
        $upcomingWindowLabel = LOC('section.upcoming_week');
        $upcomingSectionTitle = LOC('section.upcoming');
    } elseif (!empty($buckets['upcoming_month'])) {
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

    if (!empty($upcomingInvoiceLines) && $upcomingWindowLabel !== LOC('section.upcoming_week')) {
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
    $odataErrorPublic = ((int) $e->getCode() === 40901) ? $e->getMessage() : null;
}