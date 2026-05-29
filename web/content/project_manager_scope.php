<?php

/**
 * Constants
 */

const TALOS_PM_HIERARCHY_PATH = __DIR__ . '/../data/project_manager_hierarchy.json';
const TALOS_PM_SCOPE_CACHE_TTL_SECONDS = 43200;

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

function talosPmLoadHierarchyAssignments(): array
{
    $path = TALOS_PM_HIERARCHY_PATH;
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $source = $decoded;
    if (isset($decoded['assignments']) && is_array($decoded['assignments'])) {
        $source = $decoded['assignments'];
    }

    $result = [];
    foreach ($source as $manager => $children) {
        $managerName = trim((string) $manager);
        if ($managerName === '') {
            continue;
        }

        $list = is_array($children) ? $children : [];
        $normalizedChildren = [];
        foreach ($list as $child) {
            $childName = trim((string) $child);
            if ($childName === '' || talosPmNormalizeName($childName) === talosPmNormalizeName($managerName)) {
                continue;
            }

            $normalizedChildren[] = $childName;
        }

        $result[$managerName] = talosPmUniqueStrings($normalizedChildren);
    }

    return $result;
}

function talosPmSaveHierarchyAssignments(array $assignments, string $updatedBy = ''): bool
{
    $clean = [];
    foreach ($assignments as $manager => $children) {
        $managerName = trim((string) $manager);
        if ($managerName === '') {
            continue;
        }

        $childList = is_array($children) ? $children : [];
        $filtered = [];
        foreach ($childList as $child) {
            $childName = trim((string) $child);
            if ($childName === '' || talosPmNormalizeName($childName) === talosPmNormalizeName($managerName)) {
                continue;
            }

            $filtered[] = $childName;
        }

        $clean[$managerName] = talosPmUniqueStrings($filtered);
    }

    ksort($clean, SORT_NATURAL | SORT_FLAG_CASE);

    $payload = [
        'version' => 1,
        'updated_at' => gmdate('c'),
        'updated_by' => trim($updatedBy),
        'assignments' => $clean,
    ];

    $dir = dirname(TALOS_PM_HIERARCHY_PATH);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return false;
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    $tmpPath = TALOS_PM_HIERARCHY_PATH . '.tmp';
    if (@file_put_contents($tmpPath, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmpPath, TALOS_PM_HIERARCHY_PATH)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
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

function talosPmBuildNormalizedAdjacency(array $assignments): array
{
    $adjacency = [];

    foreach ($assignments as $manager => $children) {
        $managerName = trim((string) $manager);
        if ($managerName === '') {
            continue;
        }

        $managerKey = talosPmNormalizeName($managerName);
        if (!isset($adjacency[$managerKey])) {
            $adjacency[$managerKey] = [];
        }

        $childList = is_array($children) ? $children : [];
        foreach ($childList as $child) {
            $childName = trim((string) $child);
            if ($childName === '') {
                continue;
            }

            $childKey = talosPmNormalizeName($childName);
            if ($childKey === $managerKey) {
                continue;
            }

            $adjacency[$managerKey][$childKey] = true;
            if (!isset($adjacency[$childKey])) {
                $adjacency[$childKey] = [];
            }
        }
    }

    return $adjacency;
}

function talosPmCanReach(array $adjacency, string $startKey, string $targetKey): bool
{
    if ($startKey === '' || $targetKey === '') {
        return false;
    }

    if ($startKey === $targetKey) {
        return true;
    }

    $stack = [$startKey];
    $visited = [];

    while (!empty($stack)) {
        $current = array_pop($stack);
        if (isset($visited[$current])) {
            continue;
        }

        $visited[$current] = true;
        $children = $adjacency[$current] ?? [];
        foreach ($children as $childKey => $enabled) {
            if (!$enabled) {
                continue;
            }

            if ($childKey === $targetKey) {
                return true;
            }

            if (!isset($visited[$childKey])) {
                $stack[] = $childKey;
            }
        }
    }

    return false;
}

function talosPmWouldCreateCycle(array $assignments, string $manager, string $candidateChild): bool
{
    $managerKey = talosPmNormalizeName($manager);
    $childKey = talosPmNormalizeName($candidateChild);
    if ($managerKey === '' || $childKey === '') {
        return false;
    }

    if ($managerKey === $childKey) {
        return true;
    }

    $adjacency = talosPmBuildNormalizedAdjacency($assignments);
    if (!isset($adjacency[$managerKey])) {
        $adjacency[$managerKey] = [];
    }

    $adjacency[$managerKey][$childKey] = true;
    if (!isset($adjacency[$childKey])) {
        $adjacency[$childKey] = [];
    }

    return talosPmCanReach($adjacency, $childKey, $managerKey);
}

function talosPmGetDescendantManagerKeys(array $assignments, string $manager): array
{
    $adjacency = talosPmBuildNormalizedAdjacency($assignments);
    $startKey = talosPmNormalizeName($manager);
    if ($startKey === '') {
        return [];
    }

    $result = [];
    $stack = [$startKey];

    while (!empty($stack)) {
        $current = array_pop($stack);
        $children = $adjacency[$current] ?? [];
        foreach ($children as $childKey => $enabled) {
            if (!$enabled || isset($result[$childKey])) {
                continue;
            }

            $result[$childKey] = true;
            $stack[] = $childKey;
        }
    }

    return array_keys($result);
}

function talosPmBuildDisplayMap(array $values): array
{
    $map = [];

    foreach ($values as $value) {
        $name = trim((string) $value);
        if ($name === '') {
            continue;
        }

        $key = talosPmNormalizeName($name);
        if (!isset($map[$key])) {
            $map[$key] = $name;
        }
    }

    return $map;
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

function talosPmGetAllowedProjectManagers(array $assignments, string $ownProjectManager, array $knownManagers = []): array
{
    $selfName = trim($ownProjectManager);
    if ($selfName === '') {
        return [];
    }

    $displayPool = [$selfName];
    foreach ($knownManagers as $manager) {
        $displayPool[] = (string) $manager;
    }
    foreach ($assignments as $manager => $children) {
        $displayPool[] = (string) $manager;
        foreach ((array) $children as $child) {
            $displayPool[] = (string) $child;
        }
    }

    $displayMap = talosPmBuildDisplayMap($displayPool);

    $allowed = [];
    $selfKey = talosPmNormalizeName($selfName);
    $allowed[$selfKey] = $displayMap[$selfKey] ?? $selfName;

    $descendants = talosPmGetDescendantManagerKeys($assignments, $selfName);
    foreach ($descendants as $descendantKey) {
        $allowed[$descendantKey] = $displayMap[$descendantKey] ?? $descendantKey;
    }

    $result = array_values($allowed);
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function talosPmFilterRowsByAllowedManagers(array $rows, array $allowedManagers): array
{
    if (empty($allowedManagers)) {
        return [];
    }

    $allowedLookup = [];
    foreach ($allowedManagers as $allowedManager) {
        $key = talosPmNormalizeName((string) $allowedManager);
        if ($key !== '') {
            $allowedLookup[$key] = true;
        }
    }

    return array_values(array_filter($rows, static function (array $row) use ($allowedLookup): bool {
        $projectManager = trim((string) ($row['_project_manager'] ?? ''));
        if ($projectManager === '') {
            return false;
        }

        $key = talosPmNormalizeName($projectManager);
        return isset($allowedLookup[$key]);
    }));
}

function talosPmFilterBucketsByAllowedManagers(array $buckets, array $allowedManagers): array
{
    $keys = ['overdue', 'upcoming_month', 'upcoming_year', 'all'];
    foreach ($keys as $bucketKey) {
        $rows = $buckets[$bucketKey] ?? [];
        if (!is_array($rows)) {
            $buckets[$bucketKey] = [];
            continue;
        }

        $buckets[$bucketKey] = talosPmFilterRowsByAllowedManagers($rows, $allowedManagers);
    }

    return $buckets;
}

function talosPmBuildInvalidChildrenMatrix(array $allManagers, array $assignments): array
{
    $invalid = [];
    $managerList = talosPmUniqueStrings($allManagers);

    foreach ($managerList as $manager) {
        $managerKey = talosPmNormalizeName($manager);
        $invalid[$managerKey] = [$managerKey];

        foreach ($managerList as $candidate) {
            $candidateKey = talosPmNormalizeName($candidate);
            if ($candidateKey === '' || $candidateKey === $managerKey) {
                continue;
            }

            if (talosPmCanReach(talosPmBuildNormalizedAdjacency($assignments), $candidateKey, $managerKey)) {
                $invalid[$managerKey][] = $candidateKey;
            }
        }

        $invalid[$managerKey] = array_values(array_unique($invalid[$managerKey]));
    }

    return $invalid;
}

function talosPmAssignChildren(array $assignments, string $manager, array $children): array
{
    $managerName = trim($manager);
    if ($managerName === '') {
        return [
            'ok' => false,
            'error_key' => 'pm_admin.error.missing_manager',
            'assignments' => $assignments,
        ];
    }

    $managerKey = talosPmNormalizeName($managerName);
    $displayMap = talosPmBuildDisplayMap(array_merge([$managerName], array_keys($assignments), $children));
    $managerCanonical = $displayMap[$managerKey] ?? $managerName;

    $normalizedChildren = talosPmUniqueStrings($children);
    foreach ($normalizedChildren as $childName) {
        if (talosPmWouldCreateCycle($assignments, $managerCanonical, $childName)) {
            return [
                'ok' => false,
                'error_key' => 'pm_admin.error.circular_dependency',
                'error_args' => [$managerCanonical, $childName],
                'assignments' => $assignments,
            ];
        }
    }

    $updated = $assignments;

    $resolvedManagerKey = null;
    foreach (array_keys($updated) as $existingManager) {
        if (talosPmNormalizeName((string) $existingManager) === $managerKey) {
            $resolvedManagerKey = (string) $existingManager;
            break;
        }
    }

    if ($resolvedManagerKey === null) {
        $resolvedManagerKey = $managerCanonical;
    }

    $updated[$resolvedManagerKey] = $normalizedChildren;

    return [
        'ok' => true,
        'assignments' => $updated,
        'manager' => $resolvedManagerKey,
    ];
}
