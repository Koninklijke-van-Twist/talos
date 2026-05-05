<?php

/**
 * Functies
 */

const PROJECT_BILLING_CACHE_TTL_SECONDS = 43200;
const PROJECT_BILLING_FALLBACK_ALL_MAX_WEEKS = 104;
const PROJECT_BILLING_CHUNK_SIZE = 5;
const PROJECT_BILLING_MAX_CALLS_PER_REQUEST = 40;

function buildProjectInvoiceSelectClause(): string
{
    return 'Job_No,Line_No,Planning_Date,Description,Document_No,Qty_to_Invoice,Line_Amount,LVS_Bill_to_Customer_No,KVT_Bill_To_Cust_No_WO,LVS_Work_Order_No,KVT_Memo_Invoice,KVT_Status_Work_Order,User_ID';
}

function appendCompanyToRows(array $rows, string $companyName): array
{
    foreach ($rows as &$row) {
        $row['_company'] = $companyName;
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
    $queryUrl = $companyBaseUrl . 'FactureerbareProjectPlanningsRegels'
        . '?$select=' . $selectClause
        . '&$orderby=' . rawurlencode('Planning_Date asc')
        . '&$top=' . max(1, $top)
        . '&$skip=' . max(0, $skip);

    if (!$debugFetchAllRules) {
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
            . '&$orderby=' . rawurlencode('Planning_Date asc')
            . '&$top=' . max(1, $top)
            . '&$skip=' . max(0, $skip);
    }

    return odata_get_all($queryUrl, $auth, PROJECT_BILLING_CACHE_TTL_SECONDS);
}

function mergeCompanyRowsForWindow(
    array $companyNames,
    string $baseUrl,
    string $environment,
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

    foreach ($companyNames as $companyName) {
        if ($callCount >= PROJECT_BILLING_MAX_CALLS_PER_REQUEST) {
            $limitReached = true;
            break;
        }

        if (!isset($debugCompanyResults[$companyName])) {
            $debugCompanyResults[$companyName] = [
                'company' => $companyName,
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
                $auth,
                $companyName,
                $startDate,
                $endDate,
                $debugFetchAllRules,
                $skip,
                PROJECT_BILLING_CHUNK_SIZE
            );

            $debugCompanyResults[$companyName]['ok'] = true;
            $debugCompanyResults[$companyName]['count'] += count($rows);
            $debugCompanyResults[$companyName]['error'] = '';

            $windowRows = array_merge($windowRows, appendCompanyToRows($rows, $companyName));
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

function fetchPendingProjectInvoiceLines(string $baseUrl, string $environment, array $auth, string $today, bool $hideSapImports = true): array
{
    $buckets = fetchProjectInvoiceBuckets($baseUrl, $environment, $auth, $today, null, false, $hideSapImports);
    return $buckets['overdue'];
}

function fetchProjectInvoiceBuckets(
    string $baseUrl,
    string $environment,
    array $auth,
    string $today,
    ?string $selectedCompany = null,
    bool $debugFetchAllRules = false,
    bool $hideSapImports = true
): array {
    $availableCompanies = fetchAvailableCompanyNames($baseUrl, $environment, $auth);

    $companyNames = $availableCompanies;
    if ($selectedCompany !== null && $selectedCompany !== '') {
        $companyNames = array_values(array_filter(
            $availableCompanies,
            static fn(string $name): bool => $name === $selectedCompany
        ));
    }

    $overdueLines = [];
    $upcomingWeekLines = [];
    $upcomingMonthLines = [];
    $upcomingYearLines = [];
    $allLines = [];
    $debugCompanyResults = [];
    $firstErrorMessage = null;
    $callCount = 0;
    $limitReached = false;
    $pageIndex = max(1, (int) ($_GET['page'] ?? 1));
    $skip = ($pageIndex - 1) * PROJECT_BILLING_CHUNK_SIZE;

    $weekEnd = date('Y-m-d', strtotime($today . ' +7 days'));
    $monthEnd = date('Y-m-d', strtotime($today . ' +1 month'));
    $yearEnd = date('Y-m-d', strtotime($today . ' +1 year'));
    $streamMode = isset($_GET['stream']) && (string) $_GET['stream'] === '1';

    if ($debugFetchAllRules || $streamMode) {
        $allLines = mergeCompanyRowsForWindow(
            $companyNames,
            $baseUrl,
            $environment,
            $auth,
            null,
            null,
            $debugFetchAllRules,
            $skip,
            $hideSapImports,
            $debugCompanyResults,
            $firstErrorMessage,
            $callCount,
            $limitReached
        );

        $futureLines = [];
        foreach ($allLines as $line) {
            $planningDate = (string) ($line['Planning_Date'] ?? '');
            if ($planningDate === '') {
                continue;
            }

            if ($planningDate <= $today) {
                $overdueLines[] = $line;
            } elseif ($planningDate <= $weekEnd) {
                $upcomingWeekLines[] = $line;
            } elseif ($planningDate <= $monthEnd) {
                $upcomingMonthLines[] = $line;
            } elseif ($planningDate <= $yearEnd) {
                $upcomingYearLines[] = $line;
            } else {
                $futureLines[] = $line;
            }
        }
        $allLines = $futureLines;
    } else {
        $overdueEnd = $today;

        $overdueLines = mergeCompanyRowsForWindow(
            $companyNames,
            $baseUrl,
            $environment,
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

        $upcomingWeekLines = mergeCompanyRowsForWindow(
            $companyNames,
            $baseUrl,
            $environment,
            $auth,
            date('Y-m-d', strtotime($today . ' +1 day')),
            $weekEnd,
            false,
            $skip,
            $hideSapImports,
            $debugCompanyResults,
            $firstErrorMessage,
            $callCount,
            $limitReached
        );

        if (empty($upcomingWeekLines) && !$limitReached) {
            $monthStart = date('Y-m-d', strtotime($weekEnd . ' +1 day'));
            $monthWindows = buildWeeklyWindows($monthStart, $monthEnd);
            foreach ($monthWindows as $window) {
                if ($limitReached) {
                    break;
                }

                $windowRows = mergeCompanyRowsForWindow(
                    $companyNames,
                    $baseUrl,
                    $environment,
                    $auth,
                    $window[0],
                    $window[1],
                    false,
                    $skip,
                    $hideSapImports,
                    $debugCompanyResults,
                    $firstErrorMessage,
                    $callCount,
                    $limitReached
                );

                if (!empty($windowRows)) {
                    $upcomingMonthLines = $windowRows;
                    break;
                }
            }
        }

        if (empty($upcomingWeekLines) && empty($upcomingMonthLines) && !$limitReached) {
            $yearStart = date('Y-m-d', strtotime($monthEnd . ' +1 day'));
            $yearWindows = buildWeeklyWindows($yearStart, $yearEnd);
            foreach ($yearWindows as $window) {
                if ($limitReached) {
                    break;
                }

                $windowRows = mergeCompanyRowsForWindow(
                    $companyNames,
                    $baseUrl,
                    $environment,
                    $auth,
                    $window[0],
                    $window[1],
                    false,
                    $skip,
                    $hideSapImports,
                    $debugCompanyResults,
                    $firstErrorMessage,
                    $callCount,
                    $limitReached
                );

                if (!empty($windowRows)) {
                    $upcomingYearLines = $windowRows;
                    break;
                }
            }
        }

        if (empty($upcomingWeekLines) && empty($upcomingMonthLines) && empty($upcomingYearLines) && !$limitReached) {
            $fallbackStart = date('Y-m-d', strtotime($yearEnd . ' +1 day'));
            for ($weekIndex = 0; $weekIndex < PROJECT_BILLING_FALLBACK_ALL_MAX_WEEKS; $weekIndex++) {
                if ($limitReached) {
                    break;
                }

                $windowStart = date('Y-m-d', strtotime($fallbackStart . ' +' . ($weekIndex * 7) . ' days'));
                $windowEnd = date('Y-m-d', strtotime($windowStart . ' +6 days'));

                $windowRows = mergeCompanyRowsForWindow(
                    $companyNames,
                    $baseUrl,
                    $environment,
                    $auth,
                    $windowStart,
                    $windowEnd,
                    false,
                    $skip,
                    $hideSapImports,
                    $debugCompanyResults,
                    $firstErrorMessage,
                    $callCount,
                    $limitReached
                );

                if (!empty($windowRows)) {
                    $allLines = $windowRows;
                    break;
                }
            }
        }
    }

    if (
        empty($overdueLines)
        && empty($upcomingWeekLines)
        && empty($upcomingMonthLines)
        && empty($upcomingYearLines)
        && empty($allLines)
        && $firstErrorMessage !== null
    ) {
        throw new Exception($firstErrorMessage);
    }

    return [
        'overdue' => $overdueLines,
        'upcoming_week' => $upcomingWeekLines,
        'upcoming_month' => $upcomingMonthLines,
        'upcoming_year' => $upcomingYearLines,
        'all' => $allLines,
        'all_without_planning_date' => 0,
        'available_companies' => $availableCompanies,
        'week_end' => $weekEnd,
        'month_end' => $monthEnd,
        'year_end' => $yearEnd,
        'debug_fetch_all_rules' => $debugFetchAllRules,
        'debug_company_results' => array_values($debugCompanyResults),
        'is_partial' => $limitReached,
        'chunk_size' => PROJECT_BILLING_CHUNK_SIZE,
        'page' => $pageIndex,
        'calls_used' => $callCount,
        'max_calls' => PROJECT_BILLING_MAX_CALLS_PER_REQUEST,
    ];
}