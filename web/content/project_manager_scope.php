<?php

/**
 * Constants
 */

const TALOS_PM_SCOPE_CACHE_TTL_SECONDS = 82800; // 23 hours

/**
 * Functions
 */

function talosPmNormalizeName(string $value): string
{
    return strtolower(trim($value));
}

function talosPmUniqueStrings(array $values): array
{
    $seen = [];
    $result = [];

    foreach ($values as $value) {
        $text = trim((string) $value);
        if ($text === '') {
            continue;
        }

        $key = talosPmNormalizeName($text);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $result[] = $text;
    }

    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function talosPmFetchUserSetupRows(
    string $baseUrl,
    array $companyEnvironmentMap,
    array $fallbackAuth,
    string $selectedCompany = ''
): array {
    static $cache = [];

    $normalizedMap = [];
    foreach ($companyEnvironmentMap as $companyName => $environmentName) {
        $company = trim((string) $companyName);
        $environment = trim((string) $environmentName);
        if ($company === '' || $environment === '') {
            continue;
        }

        $normalizedMap[$company] = $environment;
    }

    $requestedCompany = trim($selectedCompany);
    if ($requestedCompany !== '' && isset($normalizedMap[$requestedCompany])) {
        $normalizedMap = [$requestedCompany => $normalizedMap[$requestedCompany]];
    }

    if (empty($normalizedMap) && $requestedCompany !== '') {
        $fallbackEnvironment = function_exists('getEnvironmentForCompany')
            ? (string) (getEnvironmentForCompany($requestedCompany) ?? '')
            : '';

        if ($fallbackEnvironment === '' && function_exists('getPrimaryEnvironment')) {
            $fallbackEnvironment = (string) getPrimaryEnvironment();
        }

        if ($fallbackEnvironment !== '') {
            $normalizedMap[$requestedCompany] = $fallbackEnvironment;
        }
    }

    $mapKeyParts = [];
    foreach ($normalizedMap as $company => $environment) {
        $mapKeyParts[] = $company . '@' . $environment;
    }
    sort($mapKeyParts, SORT_NATURAL | SORT_FLAG_CASE);
    $cacheKey = md5($baseUrl . '|' . implode('|', $mapKeyParts));
    if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $rows = [];
    $seen = [];
    foreach ($normalizedMap as $companyName => $environmentValue) {
        $company = trim((string) $companyName);
        $environmentValue = trim((string) $environmentValue);
        if ($company === '') {
            continue;
        }

        if ($environmentValue === '') {
            continue;
        }

        $authForEnvironment = function_exists('getAuthForEnvironment')
            ? getAuthForEnvironment($environmentValue)
            : $fallbackAuth;

        $companyBaseUrl = buildOdataCompanyUrl($baseUrl, $environmentValue, $company);
        $queryUrl = $companyBaseUrl . 'SalesPersonCard?$select=Code,Name,E_Mail';

        try {
            $environmentRows = odata_get_all($queryUrl, $authForEnvironment, TALOS_PM_SCOPE_CACHE_TTL_SECONDS);
        } catch (Exception $ignored) {
            continue;
        }
        if (!is_array($environmentRows)) {
            continue;
        }

        foreach ($environmentRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $dedupeKey = strtolower(trim((string) ($row['Code'] ?? '')))
                . '|'
                . strtolower(trim((string) ($row['E_Mail'] ?? '')))
                . '|'
                . strtolower($environmentValue);
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $row['_environment'] = $environmentValue;
            $row['_company'] = $company;
            $rows[] = $row;
        }
    }

    $cache[$cacheKey] = $rows;
    return $rows;
}

function talosPmSelectSingleCompanyEnvironmentMap(array $companyEnvironmentMap, string $selectedCompany = ''): array
{
    $normalizedMap = [];
    foreach ($companyEnvironmentMap as $companyName => $environmentName) {
        $company = trim((string) $companyName);
        $environment = trim((string) $environmentName);
        if ($company === '' || $environment === '') {
            continue;
        }

        $normalizedMap[$company] = $environment;
    }

    if (empty($normalizedMap)) {
        return [];
    }

    $requestedCompany = trim($selectedCompany);
    if ($requestedCompany !== '' && isset($normalizedMap[$requestedCompany])) {
        return [$requestedCompany => $normalizedMap[$requestedCompany]];
    }

    ksort($normalizedMap, SORT_NATURAL | SORT_FLAG_CASE);
    $company = (string) array_key_first($normalizedMap);
    if ($company === '') {
        return [];
    }

    return [$company => (string) $normalizedMap[$company]];
}

function talosPmResolveProjectManagerSelection(array $allProjectManagers, string $selection): string
{
    $requested = trim($selection);
    if ($requested === '') {
        return '';
    }

    $requestedKey = talosPmNormalizeName($requested);
    foreach ($allProjectManagers as $managerValue) {
        $manager = trim((string) $managerValue);
        if ($manager === '') {
            continue;
        }

        if (talosPmNormalizeName($manager) === $requestedKey) {
            return $manager;
        }
    }

    return '';
}

function talosPmResolveCurrentUserFromUserSetup(array $userSetupRows, string $email): array
{
    $emailTrimmed = trim($email);
    $normalizedEmail = strtolower($emailTrimmed);

    foreach ($userSetupRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowEmail = trim((string) ($row['E_Mail'] ?? ''));
        if ($rowEmail === '') {
            continue;
        }

        if (strtolower($rowEmail) !== $normalizedEmail) {
            continue;
        }

        $projectManager = trim((string) ($row['Code'] ?? ''));
        if ($projectManager === '') {
            continue;
        }

        $projectManagerName = trim((string) ($row['Name'] ?? ''));

        return [
            'found' => true,
            'email' => $rowEmail,
            'user_id' => $projectManager,
            'project_manager' => $projectManager,
            'project_manager_name' => ($projectManagerName !== '' ? $projectManagerName : $projectManager),
            'environment' => trim((string) ($row['_environment'] ?? '')),
            'row' => $row,
        ];
    }

    return [
        'found' => false,
        'email' => $emailTrimmed,
        'user_id' => '',
        'project_manager' => '',
        'project_manager_name' => '',
        'environment' => '',
        'row' => null,
    ];
}

function talosPmCollectManagerCandidates(array $rows, array $extra = []): array
{
    $values = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = trim((string) ($row['Code'] ?? ''));
        if ($code !== '') {
            $values[] = $code;
        }
    }

    foreach ($extra as $value) {
        $values[] = trim((string) $value);
    }

    return talosPmUniqueStrings($values);
}

function talosPmCollectProjectManagersFromBuckets(array $buckets): array
{
    $values = [];
    $keys = ['overdue', 'upcoming_month', 'upcoming_year', 'all'];

    foreach ($keys as $bucketKey) {
        $rows = $buckets[$bucketKey] ?? [];
        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectManager = trim((string) ($row['_project_manager'] ?? ''));
            if ($projectManager !== '') {
                $values[] = $projectManager;
            }
        }
    }

    return talosPmUniqueStrings($values);
}

function talosPmBuildSalespersonEmailLocalPartMap(array $salespersonRows): array
{
    $map = [];

    foreach ($salespersonRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = trim((string) ($row['Code'] ?? ''));
        $email = trim((string) ($row['E_Mail'] ?? ''));
        if ($code === '' || $email === '') {
            continue;
        }

        $atPos = strpos($email, '@');
        if ($atPos === false || $atPos === 0) {
            continue;
        }

        $localPart = strtolower(trim(substr($email, 0, $atPos)));
        if ($localPart === '' || isset($map[$localPart])) {
            continue;
        }

        $map[$localPart] = $code;
    }

    return $map;
}

function talosPmMapLegacyManagerCodeToSalespersonCode(string $projectManagerValue, array $salespersonRows): string
{
    $value = trim($projectManagerValue);
    if ($value === '') {
        return '';
    }

    $normalizedValue = talosPmNormalizeName($value);
    foreach ($salespersonRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = trim((string) ($row['Code'] ?? ''));
        if ($code !== '' && talosPmNormalizeName($code) === $normalizedValue) {
            return $code;
        }
    }

    $candidate = $value;
    $slashPos = strrpos($candidate, '/');
    $backslashPos = strrpos($candidate, '\\');
    $separatorPos = false;

    if ($slashPos !== false && $backslashPos !== false) {
        $separatorPos = max($slashPos, $backslashPos);
    } elseif ($slashPos !== false) {
        $separatorPos = $slashPos;
    } elseif ($backslashPos !== false) {
        $separatorPos = $backslashPos;
    }

    if ($separatorPos !== false) {
        $candidate = substr($candidate, $separatorPos + 1);
    }

    $atPos = strpos($candidate, '@');
    if ($atPos !== false) {
        $candidate = substr($candidate, 0, $atPos);
    }

    $localPart = strtolower(trim($candidate));
    if ($localPart === '') {
        return $value;
    }

    $emailLocalPartMap = talosPmBuildSalespersonEmailLocalPartMap($salespersonRows);
    if (isset($emailLocalPartMap[$localPart])) {
        return (string) $emailLocalPartMap[$localPart];
    }

    return $value;
}

function talosPmRemapLegacyManagerCodesInBuckets(array $buckets, array $salespersonRows): array
{
    $keys = ['overdue', 'upcoming_month', 'upcoming_year', 'all'];

    foreach ($keys as $bucketKey) {
        if (!isset($buckets[$bucketKey]) || !is_array($buckets[$bucketKey])) {
            continue;
        }

        foreach ($buckets[$bucketKey] as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $currentManager = trim((string) ($row['_project_manager'] ?? ''));
            if ($currentManager === '') {
                continue;
            }

            $resolvedManager = talosPmMapLegacyManagerCodeToSalespersonCode($currentManager, $salespersonRows);

            $salespersonCode = trim((string) ($row['_project_manager_salesperson_code'] ?? ''));
            $projectManagerRaw = trim((string) ($row['_project_manager_project_manager_raw'] ?? ''));
            if (
                $salespersonCode !== ''
                && $projectManagerRaw !== ''
                && talosPmNormalizeName($salespersonCode) !== talosPmNormalizeName($projectManagerRaw)
            ) {
                $mappedProjectManager = talosPmMapLegacyManagerCodeToSalespersonCode($projectManagerRaw, $salespersonRows);
                if (
                    $mappedProjectManager !== ''
                    && talosPmNormalizeName($mappedProjectManager) !== talosPmNormalizeName($projectManagerRaw)
                ) {
                    $resolvedManager = $mappedProjectManager;
                }
            }

            $row['_project_manager'] = $resolvedManager;
        }
        unset($row);
    }

    return $buckets;
}

function talosPmExtractLookupCandidatesFromUserId(string $userId): array
{
    $value = trim($userId);
    if ($value === '') {
        return [];
    }

    $candidates = [$value];

    $slashPos = strrpos($value, '/');
    $backslashPos = strrpos($value, '\\');
    $separatorPos = false;

    if ($slashPos !== false && $backslashPos !== false) {
        $separatorPos = max($slashPos, $backslashPos);
    } elseif ($slashPos !== false) {
        $separatorPos = $slashPos;
    } elseif ($backslashPos !== false) {
        $separatorPos = $backslashPos;
    }

    if ($separatorPos !== false) {
        $candidates[] = substr($value, $separatorPos + 1);
    }

    $atPos = strpos($value, '@');
    if ($atPos !== false && $atPos > 0) {
        $candidates[] = substr($value, 0, $atPos);
    }

    return talosPmUniqueStrings($candidates);
}

function talosPmResolveCreatedByDisplayName(string $userId, array $salespersonRows): string
{
    $candidates = talosPmExtractLookupCandidatesFromUserId($userId);
    if (empty($candidates)) {
        return '';
    }

    $codeToName = [];
    $emailLocalPartToName = [];

    foreach ($salespersonRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = trim((string) ($row['Code'] ?? ''));
        $name = trim((string) ($row['Name'] ?? ''));
        if ($code !== '') {
            $codeToName[talosPmNormalizeName($code)] = ($name !== '' ? $name : $code);
        }

        $email = trim((string) ($row['E_Mail'] ?? ''));
        $atPos = strpos($email, '@');
        if ($atPos !== false && $atPos > 0) {
            $localPart = strtolower(trim(substr($email, 0, $atPos)));
            if ($localPart !== '' && !isset($emailLocalPartToName[$localPart])) {
                $emailLocalPartToName[$localPart] = ($name !== '' ? $name : $code);
            }
        }
    }

    foreach ($candidates as $candidate) {
        $normalized = talosPmNormalizeName((string) $candidate);
        if (isset($codeToName[$normalized])) {
            return (string) $codeToName[$normalized];
        }
    }

    foreach ($candidates as $candidate) {
        $localPart = strtolower(trim((string) $candidate));
        if (isset($emailLocalPartToName[$localPart])) {
            return (string) $emailLocalPartToName[$localPart];
        }
    }

    return trim((string) end($candidates));
}

function talosPmApplyCreatedByDisplayNamesToBuckets(array $buckets, array $salespersonRows): array
{
    $keys = ['overdue', 'upcoming_month', 'upcoming_year', 'all'];

    foreach ($keys as $bucketKey) {
        if (!isset($buckets[$bucketKey]) || !is_array($buckets[$bucketKey])) {
            continue;
        }

        foreach ($buckets[$bucketKey] as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $userId = (string) ($row['User_ID'] ?? '');
            $row['_created_by_display'] = talosPmResolveCreatedByDisplayName($userId, $salespersonRows);
        }
        unset($row);
    }

    return $buckets;
}

function talosPmBuildProjectManagerDisplayMap(array $salespersonRows, array $extraManagerCodes = []): array
{
    $map = [];

    foreach ($salespersonRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = trim((string) ($row['Code'] ?? ''));
        if ($code === '') {
            continue;
        }

        $name = trim((string) ($row['Name'] ?? ''));
        $key = talosPmNormalizeName($code);
        if (!isset($map[$key])) {
            $map[$key] = [
                'code' => $code,
                'name' => ($name !== '' ? $name : $code),
            ];
        }
    }

    foreach ($extraManagerCodes as $codeValue) {
        $code = trim((string) $codeValue);
        if ($code === '') {
            continue;
        }

        $key = talosPmNormalizeName($code);
        if (!isset($map[$key])) {
            $map[$key] = [
                'code' => $code,
                'name' => $code,
            ];
        }
    }

    $result = [];
    foreach ($map as $item) {
        $result[$item['code']] = $item['name'];
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function talosPmApplyDisplayNamesToBuckets(array $buckets, array $displayMap): array
{
    $keys = ['overdue', 'upcoming_month', 'upcoming_year', 'all'];

    foreach ($keys as $bucketKey) {
        if (!isset($buckets[$bucketKey]) || !is_array($buckets[$bucketKey])) {
            continue;
        }

        foreach ($buckets[$bucketKey] as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['_project_manager'] ?? ''));
            if ($code === '') {
                $row['_project_manager_display'] = '';
                continue;
            }

            $displayName = (string) ($displayMap[$code] ?? '');
            if ($displayName === '') {
                foreach ($displayMap as $candidateCode => $candidateName) {
                    if (talosPmNormalizeName((string) $candidateCode) === talosPmNormalizeName($code)) {
                        $displayName = (string) $candidateName;
                        break;
                    }
                }
            }

            $row['_project_manager_display'] = $displayName !== '' ? $displayName : $code;
        }
        unset($row);
    }

    return $buckets;
}
