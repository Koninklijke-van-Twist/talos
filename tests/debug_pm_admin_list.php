<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/auth.php';
require_once __DIR__ . '/../web/content/helpers.php';
require_once __DIR__ . '/../web/content/project_billing.php';
require_once __DIR__ . '/../web/content/project_manager_scope.php';
require_once __DIR__ . '/../web/odata.php';

function out(string $text): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

$findName = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--find=')) {
        $findName = trim(substr($arg, 7));
    }
}

$activeEnvironments = function_exists('talosNormalizeEnvironmentList')
    ? talosNormalizeEnvironmentList($environment)
    : (is_array($environment) ? $environment : [trim((string) $environment)]);

if (empty($activeEnvironments)) {
    out('No active environments found in auth.php');
    exit(1);
}

$primaryEnvironment = (string) $activeEnvironments[0];
$primaryAuth = function_exists('getAuthForEnvironment')
    ? getAuthForEnvironment($primaryEnvironment)
    : ((array) ($auth_list[$primaryEnvironment] ?? []));

if (empty($primaryAuth)) {
    out('No auth config found for primary environment: ' . $primaryEnvironment);
    exit(1);
}

$today = date('Y-m-d');
out('Building admin PM list using production code path...');
out('Primary environment: ' . $primaryEnvironment);
out('Today: ' . $today);

try {
    $buckets = fetchProjectInvoiceBuckets(
        (string) $baseUrl,
        $activeEnvironments,
        $primaryAuth,
        $today,
        null,
        false,
        true
    );
} catch (Throwable $e) {
    out('Failed to fetch project buckets: ' . $e->getMessage());
    exit(1);
}

$companyEnvironmentMap = (array) ($buckets['company_environment_map'] ?? []);
out('Companies discovered: ' . count($companyEnvironmentMap));

try {
    $salespersonRows = talosPmFetchUserSetupRows(
        (string) $baseUrl,
        $companyEnvironmentMap,
        $primaryAuth,
        ''
    );
} catch (Throwable $e) {
    out('Failed to fetch SalesPersonCard rows: ' . $e->getMessage());
    exit(1);
}

$buckets = talosPmRemapLegacyManagerCodesInBuckets($buckets, $salespersonRows);
$projectManagerAssignments = talosPmLoadHierarchyAssignments();

$extraManagers = talosPmCollectManagerCandidates(
    $salespersonRows,
    talosPmCollectProjectManagersFromBuckets($buckets)
);

foreach ($projectManagerAssignments as $managerName => $children) {
    $extraManagers[] = (string) $managerName;
    foreach ((array) $children as $childName) {
        $extraManagers[] = (string) $childName;
    }
}

$allProjectManagers = talosPmUniqueStrings($extraManagers);
$displayMap = talosPmBuildProjectManagerDisplayMap($salespersonRows, $allProjectManagers);

out('SalesPersonCard rows fetched: ' . count($salespersonRows));
out('Unique manager codes in admin list: ' . count($allProjectManagers));
out('Display map entries: ' . count($displayMap));

$nameToCodes = [];
foreach ($allProjectManagers as $code) {
    $label = trim((string) ($displayMap[$code] ?? $code));
    if (!isset($nameToCodes[$label])) {
        $nameToCodes[$label] = [];
    }
    $nameToCodes[$label][] = $code;
}

$duplicateNameCount = 0;
foreach ($nameToCodes as $label => $codes) {
    if (count($codes) > 1) {
        $duplicateNameCount++;
    }
}

out('Duplicate display names: ' . $duplicateNameCount);
if ($duplicateNameCount > 0) {
    out('--- Duplicate names with codes ---');
    ksort($nameToCodes, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($nameToCodes as $label => $codes) {
        if (count($codes) <= 1) {
            continue;
        }
        sort($codes, SORT_NATURAL | SORT_FLAG_CASE);
        out($label . ' => ' . implode(', ', $codes));
    }
}

if ($findName !== null && $findName !== '') {
    out('--- Lookup: ' . $findName . ' ---');

    $matchingSalesRows = array_values(array_filter($salespersonRows, static function (array $row) use ($findName): bool {
        $name = trim((string) ($row['Name'] ?? ''));
        return strcasecmp($name, $findName) === 0;
    }));

    out('Matching SalesPersonCard rows: ' . count($matchingSalesRows));
    foreach ($matchingSalesRows as $row) {
        out('  Code=' . (string) ($row['Code'] ?? '')
            . ' | Email=' . (string) ($row['E_Mail'] ?? '')
            . ' | Company=' . (string) ($row['_company'] ?? '')
            . ' | Env=' . (string) ($row['_environment'] ?? ''));
    }

    $matchingManagerCodes = [];
    foreach ($allProjectManagers as $code) {
        $label = trim((string) ($displayMap[$code] ?? $code));
        if (strcasecmp($label, $findName) === 0) {
            $matchingManagerCodes[] = $code;
        }
    }

    out('Matching codes in admin manager list: ' . count($matchingManagerCodes));
    if (!empty($matchingManagerCodes)) {
        sort($matchingManagerCodes, SORT_NATURAL | SORT_FLAG_CASE);
        out('  ' . implode(', ', $matchingManagerCodes));
    }
}

out('Done.');
