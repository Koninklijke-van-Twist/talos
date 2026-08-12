<?php

declare(strict_types=1);

/**
 * Per-company billing snapshot: nightly full replace, hourly/live delta merge,
 * with flock-based request coalescing so concurrent callers share one BC pull.
 */

const TALOS_BILLING_DELTA_SKEW_SECONDS = 120;
const TALOS_BILLING_CHANGE_LOG_LIMIT = 100;
const TALOS_BILLING_LIVE_LOCK_WAIT_MS = 50;

function talosBillingSnapshotDir(): string
{
    if (!empty($GLOBALS['TALOS_BILLING_SNAPSHOT_DIR_OVERRIDE'])) {
        return (string) $GLOBALS['TALOS_BILLING_SNAPSHOT_DIR_OVERRIDE'];
    }

    $dir = __DIR__ . '/../cache/billing_snapshot';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    return $dir;
}

function talosBillingLockDir(): string
{
    if (!empty($GLOBALS['TALOS_BILLING_LOCK_DIR_OVERRIDE'])) {
        return (string) $GLOBALS['TALOS_BILLING_LOCK_DIR_OVERRIDE'];
    }

    $dir = __DIR__ . '/../cache/billing_locks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    return $dir;
}

function talosBillingCompanyHash(string $company): string
{
    return sha1(strtolower(trim($company)));
}

function talosBillingSnapshotPath(string $company): string
{
    return talosBillingSnapshotDir() . '/' . talosBillingCompanyHash($company) . '.json';
}

function talosBillingLockPath(string $company): string
{
    return talosBillingLockDir() . '/' . talosBillingCompanyHash($company) . '.lock';
}

function talosBillingStatePath(string $company): string
{
    return talosBillingLockDir() . '/' . talosBillingCompanyHash($company) . '.state.json';
}

function talosBillingRowKey(array $row): string
{
    return implode('|', [
        trim((string) ($row['_company'] ?? '')),
        trim((string) ($row['Job_No'] ?? '')),
        trim((string) ($row['Line_No'] ?? '')),
        trim((string) ($row['Planning_Date'] ?? '')),
    ]);
}

/**
 * @return array<string, mixed>
 */
function talosBillingEmptySnapshot(string $company, string $environment = ''): array
{
    return [
        'company' => $company,
        'environment' => $environment,
        'updated_at' => gmdate('c'),
        'last_delta_at' => null,
        'delta_field' => null,
        'version' => 0,
        'rows' => [],
        'changes' => [],
    ];
}

/**
 * @return array<string, mixed>|null
 */
function talosBillingLoadSnapshot(string $company): ?array
{
    $path = talosBillingSnapshotPath($company);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $snapshot = talosBillingEmptySnapshot($company);
    $snapshot['company'] = trim((string) ($decoded['company'] ?? $company));
    $snapshot['environment'] = trim((string) ($decoded['environment'] ?? ''));
    $snapshot['updated_at'] = (string) ($decoded['updated_at'] ?? gmdate('c'));
    $snapshot['last_delta_at'] = isset($decoded['last_delta_at']) ? (string) $decoded['last_delta_at'] : null;
    $snapshot['delta_field'] = isset($decoded['delta_field']) ? (string) $decoded['delta_field'] : null;
    if ($snapshot['delta_field'] === '') {
        $snapshot['delta_field'] = null;
    }
    $snapshot['version'] = max(0, (int) ($decoded['version'] ?? 0));
    $snapshot['rows'] = is_array($decoded['rows'] ?? null) ? array_values($decoded['rows']) : [];
    $snapshot['changes'] = is_array($decoded['changes'] ?? null) ? array_values($decoded['changes']) : [];

    return $snapshot;
}

function talosBillingSaveSnapshot(array $snapshot): bool
{
    $company = trim((string) ($snapshot['company'] ?? ''));
    if ($company === '') {
        return false;
    }

    $dir = talosBillingSnapshotDir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return false;
    }

    $path = talosBillingSnapshotPath($company);
    $tmp = $path . '.tmp';
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $state
 */
function talosBillingWriteState(string $company, array $state): void
{
    $dir = talosBillingLockDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $path = talosBillingStatePath($company);
    $tmp = $path . '.tmp';
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    @file_put_contents($tmp, $json . "\n", LOCK_EX);
    @rename($tmp, $path);
}

/**
 * @return array<string, mixed>|null
 */
function talosBillingReadState(string $company): ?array
{
    $path = talosBillingStatePath($company);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{overdue: list<array<string, mixed>>, upcoming_month: list<array<string, mixed>>, upcoming_week: list, upcoming_year: list, all: list}
 */
function talosBillingSplitRowsIntoBuckets(array $rows, string $today): array
{
    $overdue = [];
    $upcoming = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $planningDate = trim((string) ($row['Planning_Date'] ?? ''));
        if ($planningDate === '') {
            continue;
        }

        if ($planningDate <= $today) {
            $overdue[] = $row;
        } else {
            $upcoming[] = $row;
        }
    }

    if (function_exists('sortRowsByPlanningDate')) {
        sortRowsByPlanningDate($overdue);
        sortRowsByPlanningDate($upcoming);
    }

    return [
        'overdue' => $overdue,
        'upcoming_week' => [],
        'upcoming_month' => $upcoming,
        'upcoming_year' => [],
        'all' => [],
    ];
}

/**
 * @param list<array<string, mixed>> $existingRows
 * @param list<array<string, mixed>> $incomingRows
 * @return array{rows: list<array<string, mixed>>, upserted: list<string>, removed: list<string>}
 */
function talosBillingMergeRows(array $existingRows, array $incomingRows, bool $hideSapImports = true): array
{
    $indexed = [];
    foreach ($existingRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = talosBillingRowKey($row);
        if ($key === '|||') {
            continue;
        }
        $indexed[$key] = $row;
    }

    $upserted = [];
    $removed = [];

    foreach ($incomingRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $key = talosBillingRowKey($row);
        if ($key === '|||') {
            continue;
        }

        $eligible = projectInvoiceRowIsDisplayEligible($row);
        if ($eligible && $hideSapImports && function_exists('isSapImportDescription')) {
            if (isSapImportDescription((string) ($row['Description'] ?? ''))) {
                $eligible = false;
            }
        }

        if (!$eligible) {
            if (isset($indexed[$key])) {
                unset($indexed[$key]);
                $removed[] = $key;
            }
            continue;
        }

        $indexed[$key] = $row;
        $upserted[] = $key;
    }

    $upserted = array_values(array_unique($upserted));
    $removed = array_values(array_unique($removed));

    return [
        'rows' => array_values($indexed),
        'upserted' => $upserted,
        'removed' => $removed,
    ];
}

/**
 * @param array<string, mixed> $snapshot
 * @param list<string> $upserted
 * @param list<string> $removed
 * @return array<string, mixed>
 */
function talosBillingAppendChangeLog(array $snapshot, array $upserted, array $removed): array
{
    $version = (int) ($snapshot['version'] ?? 0) + 1;
    $snapshot['version'] = $version;
    $snapshot['updated_at'] = gmdate('c');

    $changes = is_array($snapshot['changes'] ?? null) ? $snapshot['changes'] : [];
    $changes[] = [
        'version' => $version,
        'upserted' => array_values($upserted),
        'removed' => array_values($removed),
        'at' => $snapshot['updated_at'],
    ];

    if (count($changes) > TALOS_BILLING_CHANGE_LOG_LIMIT) {
        $changes = array_slice($changes, -TALOS_BILLING_CHANGE_LOG_LIMIT);
    }

    $snapshot['changes'] = $changes;
    return $snapshot;
}

/**
 * Collect upsert/remove keys for versions > $sinceVersion.
 *
 * @param array<string, mixed> $snapshot
 * @return array{upserted: list<string>, removed: list<string>}
 */
function talosBillingChangesSince(array $snapshot, int $sinceVersion): array
{
    $upserted = [];
    $removed = [];
    $changes = is_array($snapshot['changes'] ?? null) ? $snapshot['changes'] : [];

    foreach ($changes as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $version = (int) ($entry['version'] ?? 0);
        if ($version <= $sinceVersion) {
            continue;
        }

        foreach ((array) ($entry['upserted'] ?? []) as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $upserted[$key] = true;
            unset($removed[$key]);
        }
        foreach ((array) ($entry['removed'] ?? []) as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $removed[$key] = true;
            unset($upserted[$key]);
        }
    }

    return [
        'upserted' => array_keys($upserted),
        'removed' => array_keys($removed),
    ];
}

function talosBillingFormatOdataDateTime(int $unixTimestamp): string
{
    return gmdate('Y-m-d\TH:i:s\Z', max(0, $unixTimestamp));
}

/**
 * @param array<string, mixed> $context Must include baseUrl, environment(s), auth, companyEnvironmentMap, today, hideSapImports
 * @param array{non_blocking?: bool, wait_ms?: int} $options
 * @return array<string, mixed>
 */
function talosBillingCoalescedRefresh(
    string $company,
    string $mode,
    array $context,
    array $options = []
): array {
    $company = trim($company);
    $mode = strtolower(trim($mode));
    if ($company === '' || !in_array($mode, ['nightly', 'hourly', 'live'], true)) {
        return [
            'ok' => false,
            'pending' => false,
            'coalesced' => false,
            'error' => 'Invalid company or mode',
            'version' => 0,
        ];
    }

    $nonBlocking = !empty($options['non_blocking']);
    $lockPath = talosBillingLockPath($company);
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0750, true);
    }

    $lockHandle = @fopen($lockPath, 'c+');
    if ($lockHandle === false) {
        return [
            'ok' => false,
            'pending' => false,
            'coalesced' => false,
            'error' => 'Unable to open lock',
            'version' => (int) (talosBillingLoadSnapshot($company)['version'] ?? 0),
        ];
    }

    $gotLock = flock($lockHandle, $nonBlocking ? (LOCK_EX | LOCK_NB) : LOCK_EX);
    if (!$gotLock) {
        fclose($lockHandle);
        $state = talosBillingReadState($company);
        $snapshot = talosBillingLoadSnapshot($company);

        return [
            'ok' => true,
            'pending' => true,
            'coalesced' => true,
            'fetched' => false,
            'mode' => $mode,
            'version' => (int) ($snapshot['version'] ?? ($state['version'] ?? 0)),
            'state' => $state['status'] ?? 'running',
        ];
    }

    $startedAt = microtime(true);
    talosBillingWriteState($company, [
        'status' => 'running',
        'mode' => $mode,
        'started_at' => gmdate('c'),
        'version' => (int) (talosBillingLoadSnapshot($company)['version'] ?? 0),
    ]);

    try {
        $result = talosBillingPerformRefresh($company, $mode, $context);
        $result['pending'] = false;
        $result['coalesced'] = false;
        $result['duration_seconds'] = round(microtime(true) - $startedAt, 3);

        talosBillingWriteState($company, [
            'status' => !empty($result['ok']) ? 'ready' : 'failed',
            'mode' => $mode,
            'finished_at' => gmdate('c'),
            'version' => (int) ($result['version'] ?? 0),
            'error' => $result['error'] ?? null,
        ]);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        return $result;
    } catch (Throwable $e) {
        $version = (int) (talosBillingLoadSnapshot($company)['version'] ?? 0);
        talosBillingWriteState($company, [
            'status' => 'failed',
            'mode' => $mode,
            'finished_at' => gmdate('c'),
            'version' => $version,
            'error' => $e->getMessage(),
        ]);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        return [
            'ok' => false,
            'pending' => false,
            'coalesced' => false,
            'fetched' => false,
            'mode' => $mode,
            'version' => $version,
            'error' => $e->getMessage(),
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ];
    }
}

/**
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function talosBillingPerformRefresh(string $company, string $mode, array $context): array
{
    $baseUrl = (string) ($context['baseUrl'] ?? '');
    $auth = is_array($context['auth'] ?? null) ? $context['auth'] : [];
    $today = (string) ($context['today'] ?? date('Y-m-d'));
    $hideSapImports = array_key_exists('hideSapImports', $context) ? (bool) $context['hideSapImports'] : true;
    $companyEnvironmentMap = is_array($context['companyEnvironmentMap'] ?? null) ? $context['companyEnvironmentMap'] : [];
    $environment = (string) ($companyEnvironmentMap[$company] ?? ($context['environment'] ?? ''));
    $activeEnvironments = $context['activeEnvironments'] ?? [$environment];

    if ($baseUrl === '' || $environment === '') {
        throw new RuntimeException('Missing baseUrl or environment for company refresh');
    }

    $existing = talosBillingLoadSnapshot($company) ?? talosBillingEmptySnapshot($company, $environment);
    $existing['company'] = $company;
    $existing['environment'] = $environment;

    if ($existing['delta_field'] === null && $mode !== 'nightly' && function_exists('probeProjectInvoiceDeltaField')) {
        $probed = probeProjectInvoiceDeltaField($baseUrl, $environment, $auth, $company);
        $existing['delta_field'] = $probed;
        // Persist probe result even before merge so we don't re-probe every minute on failure path.
        talosBillingSaveSnapshot($existing);
    }

    $useDelta = $mode !== 'nightly'
        && !empty($existing['delta_field'])
        && !empty($existing['last_delta_at'])
        && (int) ($existing['version'] ?? 0) > 0
        && function_exists('fetchProjectInvoiceDeltaRowsForCompany');

    if ($useDelta) {
        $lastDeltaUnix = strtotime((string) $existing['last_delta_at']) ?: (time() - 3600);
        $sinceIso = talosBillingFormatOdataDateTime($lastDeltaUnix - TALOS_BILLING_DELTA_SKEW_SECONDS);
        $authForEnvironment = function_exists('resolveAuthForEnvironment')
            ? resolveAuthForEnvironment($environment, $auth)
            : $auth;

        $deltaRows = fetchProjectInvoiceDeltaRowsForCompany(
            $baseUrl,
            $environment,
            $authForEnvironment,
            $company,
            $sinceIso,
            (string) $existing['delta_field']
        );

        $deltaRows = appendCompanyToRows($deltaRows, $company, $environment);

        if (!empty($deltaRows)) {
            $jobNumbers = array_values(array_filter(array_map(
                static fn(array $row): string => (string) ($row['Job_No'] ?? ''),
                $deltaRows
            )));
            $projectData = fetchProjectDetailsByJobNumbers(
                $baseUrl,
                $environment,
                $authForEnvironment,
                $company,
                $jobNumbers
            );
            $deltaRows = enrichRowsWithProjectData($deltaRows, $projectData);
        }

        $merged = talosBillingMergeRows((array) $existing['rows'], $deltaRows, $hideSapImports);
        $snapshot = $existing;
        $snapshot['rows'] = $merged['rows'];
        $snapshot['last_delta_at'] = gmdate('c');
        if ($merged['upserted'] !== [] || $merged['removed'] !== []) {
            $snapshot = talosBillingAppendChangeLog($snapshot, $merged['upserted'], $merged['removed']);
        } else {
            $snapshot['updated_at'] = gmdate('c');
        }

        if (!talosBillingSaveSnapshot($snapshot)) {
            throw new RuntimeException('Failed to save billing snapshot');
        }

        return [
            'ok' => true,
            'fetched' => true,
            'mode' => $mode,
            'strategy' => 'delta',
            'version' => (int) $snapshot['version'],
            'upserted' => count($merged['upserted']),
            'removed' => count($merged['removed']),
            'row_count' => count($snapshot['rows']),
            'delta_field' => $snapshot['delta_field'],
        ];
    }

    // Full window refresh (nightly, or hourly/live fallback without delta field / first run).
    $buckets = fetchProjectInvoiceBuckets(
        $baseUrl,
        $activeEnvironments,
        $auth,
        $today,
        $company,
        false,
        $hideSapImports
    );

    $allRows = array_merge(
        (array) ($buckets['overdue'] ?? []),
        (array) ($buckets['upcoming_month'] ?? [])
    );

    $previousKeys = [];
    foreach ((array) ($existing['rows'] ?? []) as $row) {
        if (is_array($row)) {
            $previousKeys[talosBillingRowKey($row)] = true;
        }
    }

    $nextKeys = [];
    foreach ($allRows as $row) {
        if (is_array($row)) {
            $nextKeys[talosBillingRowKey($row)] = true;
        }
    }

    $upserted = array_keys($nextKeys);
    $removed = [];
    foreach ($previousKeys as $key => $_) {
        if (!isset($nextKeys[$key])) {
            $removed[] = $key;
        }
    }

    $snapshot = talosBillingEmptySnapshot($company, $environment);
    $snapshot['delta_field'] = $existing['delta_field'];
    $snapshot['rows'] = array_values($allRows);
    $snapshot['last_delta_at'] = gmdate('c');
    $snapshot['version'] = (int) ($existing['version'] ?? 0);
    $snapshot['changes'] = is_array($existing['changes'] ?? null) ? $existing['changes'] : [];
    $snapshot = talosBillingAppendChangeLog($snapshot, $upserted, $removed);

    if ($mode === 'nightly' || $snapshot['delta_field'] === null) {
        // After a full pull, probe once so next hourly can use delta.
        if (function_exists('probeProjectInvoiceDeltaField')) {
            $authForEnvironment = function_exists('resolveAuthForEnvironment')
                ? resolveAuthForEnvironment($environment, $auth)
                : $auth;
            $snapshot['delta_field'] = probeProjectInvoiceDeltaField(
                $baseUrl,
                $environment,
                $authForEnvironment,
                $company
            );
        }
    }

    if (!talosBillingSaveSnapshot($snapshot)) {
        throw new RuntimeException('Failed to save billing snapshot');
    }

    return [
        'ok' => true,
        'fetched' => true,
        'mode' => $mode,
        'strategy' => 'full',
        'version' => (int) $snapshot['version'],
        'upserted' => count($upserted),
        'removed' => count($removed),
        'row_count' => count($snapshot['rows']),
        'delta_field' => $snapshot['delta_field'],
        'is_partial' => !empty($buckets['is_partial']),
    ];
}

/**
 * Build page buckets from snapshot, or null if snapshot missing.
 *
 * @return array<string, mixed>|null
 */
function talosBillingBucketsFromSnapshot(
    string $company,
    string $today,
    array $availableCompanies,
    array $companyEnvironmentMap
): ?array {
    $snapshot = talosBillingLoadSnapshot($company);
    if ($snapshot === null || (int) ($snapshot['version'] ?? 0) < 1) {
        return null;
    }

    $buckets = talosBillingSplitRowsIntoBuckets((array) ($snapshot['rows'] ?? []), $today);
    $buckets['available_companies'] = $availableCompanies;
    $buckets['company_environment_map'] = $companyEnvironmentMap;
    $buckets['selected_company_environment'] = $companyEnvironmentMap[$company] ?? ($snapshot['environment'] ?? null);
    $buckets['all_without_planning_date'] = 0;
    $buckets['debug_company_results'] = [];
    $buckets['is_partial'] = false;
    $buckets['chunk_size'] = PROJECT_BILLING_CHUNK_SIZE;
    $buckets['page'] = 1;
    $buckets['calls_used'] = 0;
    $buckets['max_calls'] = PROJECT_BILLING_MAX_CALLS_PER_REQUEST;
    $buckets['snapshot_version'] = (int) ($snapshot['version'] ?? 0);
    $buckets['from_snapshot'] = true;

    return $buckets;
}

/**
 * @param list<string>|null $allowedDepartments
 * @return array{version: int, upserted: list<array<string, mixed>>, removed: list<string>}
 */
function talosBillingLivePatches(
    string $company,
    int $sinceVersion,
    ?array $allowedDepartments,
    string $today
): array {
    $snapshot = talosBillingLoadSnapshot($company);
    if ($snapshot === null) {
        return [
            'version' => 0,
            'upserted' => [],
            'removed' => [],
        ];
    }

    $version = (int) ($snapshot['version'] ?? 0);
    if ($sinceVersion <= 0 || $sinceVersion >= $version) {
        // No client cursor, or already current: return empty upserts (client already has full page).
        // If sinceVersion is 0 but we want nothing on first poll after load (page has data), empty is correct.
        if ($sinceVersion >= $version) {
            return [
                'version' => $version,
                'upserted' => [],
                'removed' => [],
            ];
        }
    }

    $diff = talosBillingChangesSince($snapshot, $sinceVersion);
    $rowsByKey = [];
    foreach ((array) ($snapshot['rows'] ?? []) as $row) {
        if (is_array($row)) {
            $rowsByKey[talosBillingRowKey($row)] = $row;
        }
    }

    $upsertedRows = [];
    foreach ($diff['upserted'] as $key) {
        if (!isset($rowsByKey[$key])) {
            continue;
        }
        $upsertedRows[] = $rowsByKey[$key];
    }

    if (function_exists('talosFilterRowsByAllowedDepartments')) {
        $upsertedRows = talosFilterRowsByAllowedDepartments($upsertedRows, $allowedDepartments);
        // Removals for other departments must not be sent; only remove keys the user could see.
        if ($allowedDepartments !== null) {
            $allowedLookup = [];
            foreach (talosNormalizeDepartmentCodes($allowedDepartments) as $code) {
                $allowedLookup[strtoupper($code)] = true;
            }
            $filteredRemoved = [];
            foreach ($diff['removed'] as $key) {
                // If we no longer have the row, still send removal (user may have had it).
                $filteredRemoved[] = $key;
            }
            $diff['removed'] = $filteredRemoved;
            unset($allowedLookup);
        }
    }

    // Annotate bucket for the client.
    foreach ($upsertedRows as &$row) {
        $planningDate = trim((string) ($row['Planning_Date'] ?? ''));
        $row['_bucket'] = ($planningDate !== '' && $planningDate <= $today) ? 'overdue' : 'upcoming';
    }
    unset($row);

    return [
        'version' => $version,
        'upserted' => $upsertedRows,
        'removed' => $diff['removed'],
    ];
}
