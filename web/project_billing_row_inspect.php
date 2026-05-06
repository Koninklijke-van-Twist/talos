<?php

/**
 * Includes/requires
 */

require_once __DIR__ . '/content/bootstrap.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/odata.php';

/**
 * Functies
 */

function odataStringLiteral(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Variabelen
 */

$currentUserEmail = (string) ($_SESSION['user']['email'] ?? '');
$canInspectRows = $currentUserEmail === '' || in_array($currentUserEmail, $ictUsers ?? [], true);

/**
 * Page load
 */

header('Content-Type: application/json; charset=UTF-8');

if (!$canInspectRows) {
    echo json_encode([
        'ok' => false,
        'error' => 'Not allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$company = trim((string) ($_GET['company'] ?? ''));
$jobNo = trim((string) ($_GET['job_no'] ?? ''));
$lineNo = trim((string) ($_GET['line_no'] ?? ''));
$planningDate = trim((string) ($_GET['planning_date'] ?? ''));
$documentNo = trim((string) ($_GET['document_no'] ?? ''));

if ($company === '' || $jobNo === '' || $lineNo === '') {
    echo json_encode([
        'ok' => false,
        'error' => 'Missing row key parameters',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $companyEnvironment = null;
    if (function_exists('getEnvironmentForCompany')) {
        $companyEnvironment = getEnvironmentForCompany($company);
    }

    if ($companyEnvironment === null || $companyEnvironment === '') {
        $companyEnvironment = function_exists('getPrimaryEnvironment')
            ? getPrimaryEnvironment()
            : (is_array($environment) ? (string) ($environment[0] ?? '') : (string) $environment);
    }

    if ($companyEnvironment === '') {
        throw new Exception('Could not resolve environment for company');
    }

    $authForEnvironment = function_exists('getAuthForEnvironment')
        ? getAuthForEnvironment($companyEnvironment)
        : $auth;

    $companyBaseUrl = buildOdataCompanyUrl($baseUrl, $companyEnvironment, $company);

    $filters = [
        'Job_No eq ' . odataStringLiteral($jobNo),
        'Line_No eq ' . $lineNo,
    ];

    if ($planningDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $planningDate)) {
        $filters[] = 'Planning_Date eq ' . $planningDate;
    }
    if ($documentNo !== '') {
        $filters[] = 'Document_No eq ' . odataStringLiteral($documentNo);
    }

    $queryUrl = $companyBaseUrl
        . 'FactureerbareProjectPlanningsRegels'
        . '?$filter=' . rawurlencode(implode(' and ', $filters))
        . '&$top=1';

    $rows = odata_get_all($queryUrl, $authForEnvironment, 300);

    if (empty($rows)) {
        echo json_encode([
            'ok' => false,
            'error' => 'No matching row found',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'row' => $rows[0],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
