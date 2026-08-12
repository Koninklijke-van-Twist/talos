<?php

/**
 * Functies
 */

const PROJECT_BILLING_CACHE_TTL_SECONDS = 82800; // 23 hours
const PROJECT_BILLING_FALLBACK_ALL_MAX_WEEKS = 104;
const PROJECT_BILLING_CHUNK_SIZE = 5;
const PROJECT_BILLING_MAX_CALLS_PER_REQUEST = 40;
const PROJECT_BILLING_JOB_LOOKUP_BATCH_SIZE = 50;

const PROJECT_BILLING_DELTA_CACHE_TTL_SECONDS = 30;

function buildProjectInvoiceSelectClause(bool $includeSystemModifiedAt = false): string
{
    $fields = 'Job_No,Line_No,Planning_Date,Description,Document_No,Qty_to_Invoice,Line_Amount,LVS_Bill_to_Customer_No,KVT_Bill_To_Cust_No_WO,LVS_Work_Order_No,KVT_Memo_Invoice,KVT_Status_Work_Order,User_ID';
    if ($includeSystemModifiedAt) {
        $fields .= ',SystemModifiedAt';
    }

    return $fields;
}

function projectInvoiceRowIsDisplayEligible(array $row): bool
{
    if ((float) ($row['Qty_to_Invoice'] ?? 0) <= 0) {
        return false;
    }

    $status = trim((string) ($row['KVT_Status_Work_Order'] ?? ''));
    if ($status === '') {
        $status = trim((string) ($row['Status'] ?? 'Open'));
    }

    return in_array($status, ['Open', 'Planned', 'Checked'], true);
}

/**
 * Probe whether SystemModifiedAt is selectable on the planning-lines page.
 */
function probeProjectInvoiceDeltaField(
    string $baseUrl,
    string $environment,
    array $auth,
    string $companyName
): ?string {
    $companyBaseUrl = buildOdataCompanyUrl($baseUrl, $environment, $companyName);
    $queryUrl = $companyBaseUrl . 'FactureerbareProjectPlanningsRegels'
        . '?$top=1&$select=SystemModifiedAt';

    try {
        $resp = odata_get_json($queryUrl, $auth);
        $rows = $resp['value'] ?? null;
        if (!is_array($rows) || $rows === []) {
            // Empty page is fine; field was accepted by the server.
            return 'SystemModifiedAt';
        }

        $first = $rows[0] ?? null;
        if (is_array($first) && array_key_exists('SystemModifiedAt', $first)) {
            return 'SystemModifiedAt';
        }

        return 'SystemModifiedAt';
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Incremental pull since $modifiedSinceIso (BC DateTime). Omits qty/status filters so
 * ineligible rows can be removed from the snapshot on merge.
 *
 * @return list<array<string, mixed>>
 */
function fetchProjectInvoiceDeltaRowsForCompany(
    string $baseUrl,
    string $environment,
    array $auth,
    string $companyName,
    string $modifiedSinceIso,
    string $deltaField = 'SystemModifiedAt'
): array {
    $companyBaseUrl = buildOdataCompanyUrl($baseUrl, $environment, $companyName);
    $selectClause = buildProjectInvoiceSelectClause(true);
    $filters = [
        "(No eq '800000' or No eq '800001')",
        $deltaField . ' ge ' . $modifiedSinceIso,
    ];

    $queryUrl = $companyBaseUrl . 'FactureerbareProjectPlanningsRegels'
        . '?$filter=' . rawurlencode(implode(' and ', $filters))
        . '&$select=' . $selectClause
        . '&$orderby=' . rawurlencode($deltaField . ' asc');

    return odata_get_all($queryUrl, $auth, PROJECT_BILLING_DELTA_CACHE_TTL_SECONDS);
}

function projectBillingTranslate(string $key, ...$args): string
{
    if (function_exists('LOC')) {
        return LOC($key, ...$args);
    }

    $fallback = [
        'error.company_environment_overlap' => 'Company "%s" exists in multiple environments (%s). This is not allowed. Remove the overlap before continuing.',
    ];

    $text = $fallback[$key] ?? $key;
    return empty($args) ? $text : vsprintf($text, $args);
}

function resolveAuthForEnvironment(string $environment, array $fallbackAuth): array
{
    if (function_exists('getAuthForEnvironment')) {
        return getAuthForEnvironment($environment);
    }

    return $fallbackAuth;
}

function appendCompanyToRows(array $rows, string $companyName, string $environment): array
{
    foreach ($rows as &$row) {
        $row['_company'] = $companyName;
        $row['_environment'] = $environment;
    }
    unset($row);

    return $rows;
}

function sortRowsByPlanningDate(array &$rows): void
{
    usort($rows, static function (array $left, array $right): int {
        return strcmp((string) ($left['Planning_Date'] ?? ''), (string) ($right['Planning_Date'] ?? ''));
    });
}

function buildWeeklyWindows(string $startDate, string $endDate): array
{
    if ($startDate > $endDate) {
        return [];
    }

    $windows = [];
    $cursor = $startDate;
    while ($cursor <= $endDate) {
        $windowEnd = date('Y-m-d', strtotime($cursor . ' +6 days'));
        if ($windowEnd > $endDate) {
            $windowEnd = $endDate;
        }

        $windows[] = [$cursor, $windowEnd];
        $cursor = date('Y-m-d', strtotime($windowEnd . ' +1 day'));
    }

    return $windows;
}

function isSapImportDescription(string $description): bool
{
    $value = trim($description);
    return preg_match('/^IMPORT SAP.*JAAR [0-9]{4}$/', $value) === 1;
}

function filterSapImportRows(array $rows, bool $hideSapImports): array
{
    if (!$hideSapImports) {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $row): bool {
        return !isSapImportDescription((string) ($row['Description'] ?? ''));
    }));
}

function fetchProjectDetailsByJobNumbers(
    string $baseUrl,
    string $environment,
    array $auth,
    string $companyName,
    array $jobNumbers
): array {
    if (empty($jobNumbers)) {
        return [];
    }

    $companyBaseUrl = buildOdataCompanyUrl($baseUrl, $environment, $companyName);
    $jobNumbers = array_values(array_unique(array_filter(array_map('trim', $jobNumbers))));
    if (empty($jobNumbers)) {
        return [];
    }

    $indexed = [];
    $batches = array_chunk($jobNumbers, PROJECT_BILLING_JOB_LOOKUP_BATCH_SIZE);
    foreach ($batches as $batch) {
        $filterParts = array_map(
            static fn(string $jobNo): string => "No eq '" . str_replace("'", "''", $jobNo) . "'",
            $batch
        );
        $filterClause = '(' . implode(' or ', $filterParts) . ')';

        $queryUrl = $companyBaseUrl . 'Projecten'
            . '?$filter=' . rawurlencode($filterClause)
            . '&$select=No,KVT_Sales_Person_Code,Project_Manager,LVS_Global_Dimension_1_Code,Status';

        $projects = odata_get_all($queryUrl, $auth, PROJECT_BILLING_CACHE_TTL_SECONDS);
        foreach ($projects as $project) {
            $no = (string) ($project['No'] ?? '');
            if ($no !== '') {
                $indexed[$no] = $project;
            }
        }
    }

    return $indexed;
}

function enrichRowsWithProjectData(array $rows, array $projectsByNo): array
{
    foreach ($rows as &$row) {
        $jobNo = (string) ($row['Job_No'] ?? '');
        if ($jobNo !== '' && isset($projectsByNo[$jobNo])) {
            $project = $projectsByNo[$jobNo];
            $salespersonCode = trim((string) ($project['KVT_Sales_Person_Code'] ?? ''));
            $projectManagerRaw = trim((string) ($project['Project_Manager'] ?? ''));
            $resolvedProjectManager = $salespersonCode;
            if ($resolvedProjectManager === '') {
                $resolvedProjectManager = $projectManagerRaw;
            }

            $row['_project_manager'] = $resolvedProjectManager;
            $row['_project_manager_salesperson_code'] = $salespersonCode;
            $row['_project_manager_project_manager_raw'] = $projectManagerRaw;
            $row['_cost_center_code'] = (string) ($project['LVS_Global_Dimension_1_Code'] ?? '');
            $row['_jobcard_status'] = (string) ($project['Status'] ?? '');
            continue;
        }

        $row['_project_manager'] = '';
        $row['_project_manager_salesperson_code'] = '';
        $row['_project_manager_project_manager_raw'] = '';
        $row['_cost_center_code'] = '';
        $row['_jobcard_status'] = '';
    }
    unset($row);

    return $rows;
}
function fetchProjectInvoiceRowsForCompanyWindow(
    string $baseUrl,
    string $environment,
    array $auth,
    string $companyName,
    ?string $startDate,
    ?string $endDate,
    bool $debugFetchAllRules,
    int $skip = 0,
    int $top = PROJECT_BILLING_CHUNK_SIZE
): array {
    $companyBaseUrl = buildOdataCompanyUrl($baseUrl, $environment, $companyName);
    $selectClause = buildProjectInvoiceSelectClause();

    // Stable URLs (no $skip/$top): odata_get_all follows @odata.nextLink and caches the full set.
    // Nightly and page-load share the same cache keys this way.
    if ($debugFetchAllRules) {
        $queryUrl = $companyBaseUrl . 'FactureerbareProjectPlanningsRegels'
            . '?$select=' . $selectClause
            . '&$orderby=' . rawurlencode('Planning_Date asc');
    } else {
        $filters = ['Qty_to_Invoice gt 0'];
        $filters[] = "(No eq '800000' or No eq '800001')";
        // Standard BC option values for Work Order status.
        $filters[] = "(KVT_Status_Work_Order eq 'Open' or KVT_Status_Work_Order eq 'Planned' or KVT_Status_Work_Order eq 'Checked')";
        if ($startDate !== null && $startDate !== '') {
            $filters[] = 'Planning_Date ge ' . $startDate;
        }
        if ($endDate !== null && $endDate !== '') {
            $filters[] = 'Planning_Date le ' . $endDate;
        }

        $queryUrl = $companyBaseUrl . 'FactureerbareProjectPlanningsRegels'
            . '?$filter=' . rawurlencode(implode(' and ', $filters))
            . '&$select=' . $selectClause
            . '&$orderby=' . rawurlencode('Planning_Date asc');
    }

    return odata_get_all($queryUrl, $auth, PROJECT_BILLING_CACHE_TTL_SECONDS);
}

function mergeCompanyRowsForWindow(
    array $companyNames,
    array $companyEnvironmentMap,
    string $baseUrl,
    array $activeEnvironments,
    array $auth,
    ?string $startDate,
    ?string $endDate,
    bool $debugFetchAllRules,
    int $skip,
    bool $hideSapImports,
    array &$debugCompanyResults,
    ?string &$firstErrorMessage,
    int &$callCount,
    bool &$limitReached
): array {
    $windowRows = [];
    $primaryEnvironment = !empty($activeEnvironments) ? (string) $activeEnvironments[0] : '';

    foreach ($companyNames as $companyName) {
        if ($callCount >= PROJECT_BILLING_MAX_CALLS_PER_REQUEST) {
            $limitReached = true;
            break;
        }

        $environment = (string) ($companyEnvironmentMap[$companyName] ?? $primaryEnvironment);
        if ($environment === '') {
            continue;
        }

        $authForEnvironment = resolveAuthForEnvironment($environment, $auth);

        if (!isset($debugCompanyResults[$companyName])) {
            $debugCompanyResults[$companyName] = [
                'company' => $companyName,
                'environment' => $environment,
                'ok' => false,
                'count' => 0,
                'error' => '',
            ];
        }

        try {
            $callCount++;
            $rows = fetchProjectInvoiceRowsForCompanyWindow(
                $baseUrl,
                $environment,
                $authForEnvironment,
                $companyName,
                $startDate,
                $endDate,
                $debugFetchAllRules,
                0,
                PROJECT_BILLING_CHUNK_SIZE
            );

            if (!empty($rows)) {
                $jobNumbers = array_values(array_filter(array_map(
                    static fn(array $row): string => (string) ($row['Job_No'] ?? ''),
                    $rows
                )));

                if (!empty($jobNumbers)) {
                    $callCount++;
                    $projectData = fetchProjectDetailsByJobNumbers(
                        $baseUrl,
                        $environment,
                        $authForEnvironment,
                        $companyName,
                        $jobNumbers
                    );
                    $rows = enrichRowsWithProjectData($rows, $projectData);
                }
            }

            $debugCompanyResults[$companyName]['ok'] = true;
            $debugCompanyResults[$companyName]['count'] += count($rows);
            $debugCompanyResults[$companyName]['error'] = '';
            $debugCompanyResults[$companyName]['environment'] = $environment;

            $windowRows = array_merge($windowRows, appendCompanyToRows($rows, $companyName, $environment));
        } catch (Exception $e) {
            $debugCompanyResults[$companyName]['error'] = $e->getMessage();
            if ($firstErrorMessage === null) {
                $firstErrorMessage = $e->getMessage();
            }
        }
    }

    $windowRows = filterSapImportRows($windowRows, $hideSapImports);
    sortRowsByPlanningDate($windowRows);
    return $windowRows;
}

function fetchAvailableCompanyNames(string $baseUrl, string $environment, array $auth): array
{
    $rootUrl = buildOdataRootUrl($baseUrl, $environment);

    // In sommige BC-omgevingen geeft `Companies` op root een 404 als er geen
    // default company is ingesteld. `Company` werkt daar meestal wel.
    try {
        $companies = odata_get_all(
            $rootUrl . 'Company?$select=Name,Display_Name',
            $auth,
            PROJECT_BILLING_CACHE_TTL_SECONDS
        );
    } catch (Exception $firstError) {
        $companies = odata_get_all(
            $rootUrl . 'Companies?$select=Name,Display_Name',
            $auth,
            PROJECT_BILLING_CACHE_TTL_SECONDS
        );
    }

    $names = [];
    foreach ($companies as $company) {
        $name = (string) ($company['Name'] ?? '');
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }

    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function fetchAvailableCompanyContext(string $baseUrl, array $activeEnvironments, array $fallbackAuth): array
{
    $allNames = [];
    $companyEnvironmentMap = [];

    foreach ($activeEnvironments as $environment) {
        $environmentName = (string) $environment;
        if ($environmentName === '') {
            continue;
        }

        $authForEnvironment = resolveAuthForEnvironment($environmentName, $fallbackAuth);
        $namesForEnvironment = fetchAvailableCompanyNames($baseUrl, $environmentName, $authForEnvironment);

        foreach ($namesForEnvironment as $companyName) {
            if (isset($companyEnvironmentMap[$companyName]) && $companyEnvironmentMap[$companyName] !== $environmentName) {
                $message = projectBillingTranslate(
                    'error.company_environment_overlap',
                    $companyName,
                    $companyEnvironmentMap[$companyName] . ', ' . $environmentName
                );
                throw new Exception($message, 40901);
            }

            $companyEnvironmentMap[$companyName] = $environmentName;
            if (!in_array($companyName, $allNames, true)) {
                $allNames[] = $companyName;
            }
        }
    }

    sort($allNames, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'available_companies' => $allNames,
        'company_environment_map' => $companyEnvironmentMap,
    ];
}

function fetchPendingProjectInvoiceLines(string $baseUrl, $environment, array $auth, string $today, bool $hideSapImports = true): array
{
    $buckets = fetchProjectInvoiceBuckets($baseUrl, $environment, $auth, $today, null, false, $hideSapImports);
    return $buckets['overdue'];
}

function fetchProjectInvoiceBuckets(
    string $baseUrl,
    $environment,
    array $auth,
    string $today,
    ?string $selectedCompany = null,
    bool $debugFetchAllRules = false,
    bool $hideSapImports = true
): array {
    $activeEnvironments = function_exists('talosNormalizeEnvironmentList')
        ? talosNormalizeEnvironmentList($environment)
        : (is_array($environment) ? $environment : [trim((string) $environment)]);

    if (empty($activeEnvironments)) {
        $activeEnvironments = ['kvtmdlive_aad'];
    }

    $companyContext = fetchAvailableCompanyContext($baseUrl, $activeEnvironments, $auth);
    $availableCompanies = $companyContext['available_companies'];
    $companyEnvironmentMap = $companyContext['company_environment_map'];

    $selectedCompanyEnvironment = null;
    if ($selectedCompany !== null && $selectedCompany !== '') {
        $selectedCompanyEnvironment = $companyEnvironmentMap[$selectedCompany] ?? null;
    }

    $companyNames = $availableCompanies;
    if ($selectedCompany !== null && $selectedCompany !== '') {
        $companyNames = array_values(array_filter(
            $availableCompanies,
            static fn(string $name): bool => $name === $selectedCompany
        ));
    }

    $overdueLines = [];
    $upcomingMonthLines = [];
    $upcomingYearLines = [];
    $allLines = [];
    $debugCompanyResults = [];
    $firstErrorMessage = null;
    $callCount = 0;
    $limitReached = false;
    $pageIndex = 1;
    $skip = 0;

    if ($debugFetchAllRules) {
        $allLines = mergeCompanyRowsForWindow(
            $companyNames,
            $companyEnvironmentMap,
            $baseUrl,
            $activeEnvironments,
            $auth,
            null,
            null,
            true,
            $skip,
            $hideSapImports,
            $debugCompanyResults,
            $firstErrorMessage,
            $callCount,
            $limitReached
        );

        foreach ($allLines as $line) {
            $planningDate = (string) ($line['Planning_Date'] ?? '');
            if ($planningDate === '') {
                continue;
            }

            if ($planningDate <= $today) {
                $overdueLines[] = $line;
            } else {
                $upcomingMonthLines[] = $line;
            }
        }
        $allLines = [];
    } else {
        $overdueEnd = $today;

        $overdueLines = mergeCompanyRowsForWindow(
            $companyNames,
            $companyEnvironmentMap,
            $baseUrl,
            $activeEnvironments,
            $auth,
            null,
            $overdueEnd,
            false,
            $skip,
            $hideSapImports,
            $debugCompanyResults,
            $firstErrorMessage,
            $callCount,
            $limitReached
        );

        $upcomingMonthLines = mergeCompanyRowsForWindow(
            $companyNames,
            $companyEnvironmentMap,
            $baseUrl,
            $activeEnvironments,
            $auth,
            date('Y-m-d', strtotime($today . ' +1 day')),
            null,
            false,
            $skip,
            $hideSapImports,
            $debugCompanyResults,
            $firstErrorMessage,
            $callCount,
            $limitReached
        );
    }

    if (
        empty($overdueLines)
        && empty($upcomingMonthLines)
        && empty($upcomingYearLines)
        && empty($allLines)
        && $firstErrorMessage !== null
    ) {
        throw new Exception($firstErrorMessage);
    }

    return [
        'overdue' => $overdueLines,
        'upcoming_week' => [],
        'upcoming_month' => $upcomingMonthLines,
        'upcoming_year' => $upcomingYearLines,
        'all' => $allLines,
        'all_without_planning_date' => 0,
        'available_companies' => $availableCompanies,
        'company_environment_map' => $companyEnvironmentMap,
        'selected_company_environment' => $selectedCompanyEnvironment,
        'month_end' => null,
        'year_end' => null,
        'debug_fetch_all_rules' => $debugFetchAllRules,
        'debug_company_results' => array_values($debugCompanyResults),
        'is_partial' => $limitReached,
        'chunk_size' => PROJECT_BILLING_CHUNK_SIZE,
        'page' => $pageIndex,
        'calls_used' => $callCount,
        'max_calls' => PROJECT_BILLING_MAX_CALLS_PER_REQUEST,
    ];
}