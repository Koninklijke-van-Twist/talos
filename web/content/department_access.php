<?php

/**
 * Department access map and helpers.
 */

const TALOS_DEPARTMENT_ACCESS_PATH = __DIR__ . '/../data/department_access.json';
const TALOS_DEPARTMENT_LIST_CACHE_TTL_SECONDS = 82800;

/**
 * @return array{users: array<string, list<string>>}
 */
function talosDepartmentAccessEmptyPayload(): array
{
    return ['users' => []];
}

function talosNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

/**
 * @param list<mixed> $departments
 * @return list<string>
 */
function talosNormalizeDepartmentCodes(array $departments): array
{
    $normalized = [];
    foreach ($departments as $department) {
        $code = trim((string) $department);
        if ($code === '') {
            continue;
        }

        if (ctype_digit($code)) {
            $code = (string) ((int) $code);
        }

        $normalized[$code] = $code;
    }

    $values = array_values($normalized);
    usort($values, static function (string $left, string $right): int {
        if (ctype_digit($left) && ctype_digit($right)) {
            return ((int) $left) <=> ((int) $right);
        }

        return strnatcasecmp($left, $right);
    });

    return $values;
}

/**
 * Order-invariant key for a department set, e.g. [65,50,80] => "50|65|80".
 *
 * @param list<mixed> $departments
 */
function talosDepartmentSetKey(array $departments): string
{
    return implode('|', talosNormalizeDepartmentCodes($departments));
}

/**
 * @param array{users?: mixed}|array $payload
 * @return array{users: array<string, list<string>>}
 */
function talosNormalizeDepartmentAccessPayload(array $payload): array
{
    $usersSource = $payload['users'] ?? $payload;
    if (!is_array($usersSource)) {
        return talosDepartmentAccessEmptyPayload();
    }

    $users = [];
    foreach ($usersSource as $email => $departments) {
        $normalizedEmail = talosNormalizeEmail((string) $email);
        if ($normalizedEmail === '' || !is_array($departments)) {
            continue;
        }

        $codes = talosNormalizeDepartmentCodes($departments);
        if ($codes === []) {
            continue;
        }

        $users[$normalizedEmail] = $codes;
    }

    ksort($users, SORT_NATURAL | SORT_FLAG_CASE);

    return ['users' => $users];
}

/**
 * @return array{users: array<string, list<string>>}
 */
function talosLoadDepartmentAccessMap(): array
{
    $path = TALOS_DEPARTMENT_ACCESS_PATH;
    if (!is_file($path)) {
        return talosDepartmentAccessEmptyPayload();
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return talosDepartmentAccessEmptyPayload();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return talosDepartmentAccessEmptyPayload();
    }

    return talosNormalizeDepartmentAccessPayload($decoded);
}

/**
 * @param array{users?: mixed}|array $payload
 */
function talosSaveDepartmentAccessMap(array $payload): bool
{
    $clean = talosNormalizeDepartmentAccessPayload($payload);
    $dir = dirname(TALOS_DEPARTMENT_ACCESS_PATH);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return false;
    }

    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    $tmpPath = TALOS_DEPARTMENT_ACCESS_PATH . '.tmp';
    if (@file_put_contents($tmpPath, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmpPath, TALOS_DEPARTMENT_ACCESS_PATH)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
}

/**
 * @return list<string>
 */
function talosDepartmentsForEmail(string $email, ?array $accessMap = null): array
{
    $normalizedEmail = talosNormalizeEmail($email);
    if ($normalizedEmail === '') {
        return [];
    }

    $map = $accessMap ?? talosLoadDepartmentAccessMap();
    $users = $map['users'] ?? [];
    if (!is_array($users)) {
        return [];
    }

    $departments = $users[$normalizedEmail] ?? null;
    if (!is_array($departments)) {
        return [];
    }

    return talosNormalizeDepartmentCodes($departments);
}

function talosIsIctUserEmail(string $email, ?array $ictUsersList = null): bool
{
    $normalizedEmail = talosNormalizeEmail($email);
    if ($normalizedEmail === '') {
        return false;
    }

    if ($ictUsersList === null) {
        global $ictUsers;
        $ictUsersList = is_array($ictUsers ?? null) ? $ictUsers : [];
    }

    foreach ($ictUsersList as $candidate) {
        if (talosNormalizeEmail((string) $candidate) === $normalizedEmail) {
            return true;
        }
    }

    return false;
}

function talosCurrentUserHasAllDepartments(): bool
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return false;
    }

    return array_key_exists('allowed_departments', $_SESSION['user'])
        && $_SESSION['user']['allowed_departments'] === null;
}

/**
 * @return list<string>|null null means all departments
 */
function talosCurrentUserAllowedDepartments(): ?array
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return [];
    }

    if (!array_key_exists('allowed_departments', $_SESSION['user'])) {
        return [];
    }

    $value = $_SESSION['user']['allowed_departments'];
    if ($value === null) {
        return null;
    }

    if (!is_array($value)) {
        return [];
    }

    return talosNormalizeDepartmentCodes($value);
}

/**
 * @param list<array<string, mixed>> $rows
 * @param list<string>|null $allowedDepartments null = all
 * @return list<array<string, mixed>>
 */
function talosFilterRowsByAllowedDepartments(array $rows, ?array $allowedDepartments): array
{
    if ($allowedDepartments === null) {
        return array_values($rows);
    }

    $allowedLookup = [];
    foreach (talosNormalizeDepartmentCodes($allowedDepartments) as $code) {
        $allowedLookup[strtoupper($code)] = true;
    }

    if ($allowedLookup === []) {
        return [];
    }

    return array_values(array_filter($rows, static function (array $row) use ($allowedLookup): bool {
        $code = trim((string) ($row['_cost_center_code'] ?? ''));
        if ($code === '') {
            return false;
        }

        return isset($allowedLookup[strtoupper($code)]);
    }));
}

/**
 * @param array<string, mixed> $buckets
 * @param list<string>|null $allowedDepartments
 * @return array<string, mixed>
 */
function talosFilterBucketsByAllowedDepartments(array $buckets, ?array $allowedDepartments): array
{
    if ($allowedDepartments === null) {
        return $buckets;
    }

    $keys = ['overdue', 'upcoming_week', 'upcoming_month', 'upcoming_year', 'all'];
    foreach ($keys as $key) {
        if (!isset($buckets[$key]) || !is_array($buckets[$key])) {
            continue;
        }

        $buckets[$key] = talosFilterRowsByAllowedDepartments($buckets[$key], $allowedDepartments);
    }

    return $buckets;
}

function talosDimensionValueHasTextLabel(string $name): bool
{
    $normalized = trim($name);
    if ($normalized === '') {
        return false;
    }

    return (bool) preg_match('/\p{L}/u', $normalized);
}

/**
 * @return list<array{code: string, name: string, label: string}>
 */
function talosFetchDepartmentOptionsFromCompany(
    string $baseUrl,
    string $environment,
    array $auth,
    string $companyName,
    int $ttlSeconds = TALOS_DEPARTMENT_LIST_CACHE_TTL_SECONDS
): array {
    if (!function_exists('buildOdataCompanyUrl') || !function_exists('odata_get_all')) {
        return [];
    }

    $company = trim($companyName);
    if ($company === '') {
        return [];
    }

    $companyBaseUrl = buildOdataCompanyUrl($baseUrl, $environment, $company);
    $queryUrl = $companyBaseUrl . 'DimensionValueList'
        . '?$select=' . rawurlencode('Dimension_Code,Code,Name,Blocked')
        . '&$filter=' . rawurlencode('Blocked eq false');

    try {
        $rows = odata_get_all($queryUrl, $auth, $ttlSeconds);
    } catch (Throwable $ignored) {
        return [];
    }

    if (!is_array($rows)) {
        return [];
    }

    $options = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !empty($row['Blocked'])) {
            continue;
        }

        $code = trim((string) ($row['Code'] ?? ''));
        if ($code === '' || !ctype_digit($code) || (int) $code >= 100) {
            continue;
        }

        $name = trim((string) ($row['Name'] ?? ''));
        if (!talosDimensionValueHasTextLabel($name) || isset($seen[$code])) {
            continue;
        }

        $seen[$code] = true;
        $options[] = [
            'code' => $code,
            'name' => $name,
            'label' => $code . ' - ' . $name,
        ];
    }

    usort($options, static function (array $left, array $right): int {
        return ((int) $left['code']) <=> ((int) $right['code']);
    });

    return $options;
}

/**
 * @param array<string, string> $companyEnvironmentMap
 * @return list<array{code: string, name: string, label: string}>
 */
function talosResolveDepartmentOptions(
    string $baseUrl,
    $environment,
    array $auth,
    array $companyEnvironmentMap = [],
    string $preferredCompany = '',
    ?array $accessMap = null
): array {
    $activeEnvironments = function_exists('talosNormalizeEnvironmentList')
        ? talosNormalizeEnvironmentList($environment)
        : (is_array($environment) ? $environment : [trim((string) $environment)]);

    $fallbackAuth = $auth;
    $map = $companyEnvironmentMap;
    if ($map === [] && function_exists('fetchAvailableCompanyContext')) {
        try {
            $context = fetchAvailableCompanyContext($baseUrl, $activeEnvironments, $fallbackAuth);
            $map = (array) ($context['company_environment_map'] ?? []);
        } catch (Throwable $ignored) {
            $map = [];
        }
    }

    $preferred = trim($preferredCompany);
    $candidates = [];
    if ($preferred !== '' && isset($map[$preferred])) {
        $candidates[$preferred] = (string) $map[$preferred];
    }
    foreach ($map as $company => $envName) {
        $companyName = trim((string) $company);
        if ($companyName === '' || isset($candidates[$companyName])) {
            continue;
        }
        $candidates[$companyName] = (string) $envName;
    }

    $options = [];
    foreach ($candidates as $companyName => $environmentName) {
        $authForEnvironment = function_exists('getAuthForEnvironment')
            ? getAuthForEnvironment((string) $environmentName)
            : $fallbackAuth;
        $options = talosFetchDepartmentOptionsFromCompany(
            $baseUrl,
            (string) $environmentName,
            $authForEnvironment,
            (string) $companyName
        );
        if ($options !== []) {
            break;
        }
    }

    $mapPayload = $accessMap ?? talosLoadDepartmentAccessMap();
    $knownCodes = [];
    foreach (($mapPayload['users'] ?? []) as $departments) {
        if (!is_array($departments)) {
            continue;
        }
        foreach (talosNormalizeDepartmentCodes($departments) as $code) {
            $knownCodes[$code] = $code;
        }
    }

    $byCode = [];
    foreach ($options as $option) {
        $byCode[$option['code']] = $option;
    }
    foreach ($knownCodes as $code) {
        if (isset($byCode[$code])) {
            continue;
        }
        $byCode[$code] = [
            'code' => $code,
            'name' => $code,
            'label' => $code,
        ];
    }

    $merged = array_values($byCode);
    usort($merged, static function (array $left, array $right): int {
        $leftDigit = ctype_digit($left['code']);
        $rightDigit = ctype_digit($right['code']);
        if ($leftDigit && $rightDigit) {
            return ((int) $left['code']) <=> ((int) $right['code']);
        }

        return strnatcasecmp($left['code'], $right['code']);
    });

    return $merged;
}

/**
 * @param array<string, list<string>> $users
 * @return list<array{key: string, departments: list<string>, users: list<array{email: string, departments: list<string>}>}>
 */
function talosGroupUsersByDepartmentSet(array $users): array
{
    $groups = [];

    foreach ($users as $email => $departments) {
        $normalizedEmail = talosNormalizeEmail((string) $email);
        $codes = talosNormalizeDepartmentCodes(is_array($departments) ? $departments : []);
        if ($normalizedEmail === '' || $codes === []) {
            continue;
        }

        $key = talosDepartmentSetKey($codes);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'departments' => $codes,
                'users' => [],
            ];
        }

        $groups[$key]['users'][] = [
            'email' => $normalizedEmail,
            'departments' => $codes,
        ];
    }

    foreach ($groups as &$group) {
        usort($group['users'], static function (array $left, array $right): int {
            return strcasecmp((string) $left['email'], (string) $right['email']);
        });
    }
    unset($group);

    $list = array_values($groups);
    usort($list, static function (array $left, array $right): int {
        $leftCount = count($left['departments']);
        $rightCount = count($right['departments']);
        if ($leftCount !== $rightCount) {
            return $rightCount <=> $leftCount;
        }

        $leftSum = 0;
        foreach ($left['departments'] as $code) {
            $leftSum += ctype_digit((string) $code) ? (int) $code : 0;
        }
        $rightSum = 0;
        foreach ($right['departments'] as $code) {
            $rightSum += ctype_digit((string) $code) ? (int) $code : 0;
        }

        if ($leftSum !== $rightSum) {
            return $rightSum <=> $leftSum;
        }

        return strcmp((string) $left['key'], (string) $right['key']);
    });

    return $list;
}
