<?php

/**
 * Functies
 */

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatDate(string $dateStr): string
{
    if ($dateStr === '' || $dateStr === '0001-01-01') {
        return '';
    }
    // Strip time part if present (e.g. 2026-04-28T00:00:00)
    $datePart = explode('T', $dateStr)[0];
    $segments = explode('-', $datePart);
    if (count($segments) !== 3) {
        return $dateStr;
    }
    return $segments[2] . '-' . $segments[1] . '-' . $segments[0];
}

function formatCurrency(float $amount): string
{
    $sign = $amount < 0 ? '-' : '';
    $formatted = number_format(abs($amount), 2, ',', '.');
    return $sign . chr(0xe2) . chr(0x82) . chr(0xac) . ' ' . $formatted;
}

function daysOverdue(string $dueDateStr): int
{
    if ($dueDateStr === '' || $dueDateStr === '0001-01-01') {
        return 0;
    }
    $datePart = explode('T', $dueDateStr)[0];
    $due = strtotime($datePart);
    if ($due === false) {
        return 0;
    }
    $today = strtotime(date('Y-m-d'));
    $diff = $today - $due;
    return max(0, (int) ($diff / 86400));
}

function validateApiKey(string $incoming, array $apiKeys): bool
{
    if ($incoming === '') {
        return false;
    }
    foreach ($apiKeys as $key) {
        if ($incoming === $key) {
            return true;
        }
    }
    return false;
}

function buildOdataCompanyUrl(string $baseUrl, string $environment, string $company): string
{
    return rtrim($baseUrl, '/') . '/' . $environment . '/ODataV4/Company%28%27' . rawurlencode($company) . '%27%29/';
}

function buildOdataRootUrl(string $baseUrl, string $environment): string
{
    return rtrim($baseUrl, '/') . '/' . $environment . '/ODataV4/';
}

function buildOdataMetadataUrl(string $baseUrl, string $environment): string
{
    return buildOdataRootUrl($baseUrl, $environment) . '$metadata';
}

function getOverdueBadgeClass(int $days): string
{
    return $days >= 60 ? 'critical' : ($days >= 30 ? 'warning' : 'mild');
}

function getUpcomingBadgeClass(int $daysUntilDue): string
{
    return $daysUntilDue <= 2 ? 'warning' : 'mild';
}

function renderDaysBadge(int $value, string $class): string
{
    return '<span class="badge-days ' . h($class) . '">' . h((string) $value) . '</span>';
}

function getStatusBadgeClass(string $status): string
{
    $status = (string) $status;
    return match ($status) {
        'Open' => 'status-open',
        'Checked' => 'status-checked',
        'Planned' => 'status-planned',
        'Cancelled' => 'status-cancelled',
        'Invoiced' => 'status-invoiced',
        'Closed' => 'status-closed',
        'Completed' => 'status-closed',
        'Gecontroleerd' => 'status-checked',
        'Gepland' => 'status-planned',
        'geannuleerd' => 'status-cancelled',
        'Gefactureerd' => 'status-invoiced',
        'afgesloten' => 'status-closed',
        default => 'status-unknown'
    };
}

function renderStatusBadge(string $status): string
{
    $status = (string) $status;
    $class = getStatusBadgeClass($status);
    return '<span class="badge-status ' . h($class) . '" data-status="' . h($status) . '">' . h($status) . '</span>';
}

function extractAccountManagerFromUserId(string $userId): string
{
    $value = trim($userId);
    if ($value === '') {
        return '';
    }

    $backslashPos = strrpos($value, '\\');
    $slashPos = strrpos($value, '/');
    $separatorPos = false;

    if ($backslashPos !== false && $slashPos !== false) {
        $separatorPos = max($backslashPos, $slashPos);
    } elseif ($backslashPos !== false) {
        $separatorPos = $backslashPos;
    } elseif ($slashPos !== false) {
        $separatorPos = $slashPos;
    }

    if ($separatorPos === false) {
        return $value;
    }

    return substr($value, $separatorPos + 1);
}

function renderInvoiceTableRow(
    array $line,
    bool $isOverdue,
    bool $includeCompanyColumn = true,
    bool $includeInspectorData = false
): string {
    $planningDateRaw = (string) ($line['Planning_Date'] ?? '');
    $jobNo = (string) ($line['Job_No'] ?? '');
    $lineNo = (string) ($line['Line_No'] ?? '');
    $company = (string) ($line['_company'] ?? '');
    $customerNo = (string) (($line['LVS_Bill_to_Customer_No'] ?? '') !== '' ? ($line['LVS_Bill_to_Customer_No'] ?? '') : ($line['KVT_Bill_To_Cust_No_WO'] ?? ''));
    $qtyToInvoice = (float) ($line['Qty_to_Invoice'] ?? 0);
    $lineAmount = (float) ($line['Line_Amount'] ?? 0);
    $rowKey = implode('|', [$company, $jobNo, $lineNo, $planningDateRaw]);

    if ($isOverdue) {
        $days = daysOverdue($planningDateRaw);
        $badge = renderDaysBadge($days, getOverdueBadgeClass($days));
        $sortDays = $days;
    } else {
        $daysUntilDue = max(0, (int) ((strtotime($planningDateRaw) - strtotime(date('Y-m-d'))) / 86400));
        $badge = renderDaysBadge($daysUntilDue, getUpcomingBadgeClass($daysUntilDue));
        $sortDays = 0;
    }

    $rowAttributes = 'data-row-key="' . h($rowKey) . '" data-sort-days="' . h((string) $sortDays) . '" data-line-amount="' . h((string) $lineAmount) . '"';
    $rowClass = '';
    if ($includeInspectorData) {
        $inspectMeta = [
            'company' => $company,
            'job_no' => $jobNo,
            'line_no' => $lineNo,
            'planning_date' => $planningDateRaw,
            'document_no' => (string) ($line['Document_No'] ?? ''),
        ];
        $inspectMetaJson = json_encode($inspectMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($inspectMetaJson)) {
            $inspectMetaJson = '{}';
        }

        $lineJson = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($lineJson)) {
            $lineJson = '{}';
        }

        $rowAttributes .= ' data-inspect-meta="' . h(base64_encode($inspectMetaJson)) . '"';
        $rowAttributes .= ' data-row-json="' . h(base64_encode($lineJson)) . '"';
        $rowClass = ' class="row-inspectable"';
    }

    $status = (string) (($line['KVT_Status_Work_Order'] ?? '') !== '' ? ($line['KVT_Status_Work_Order'] ?? '') : ($line['Status'] ?? 'Open'));
    $statusBadge = renderStatusBadge($status);
    $accountManager = extractAccountManagerFromUserId((string) ($line['User_ID'] ?? ''));

    $html = '<tr ' . $rowAttributes . $rowClass . '>'
        . '<td data-col="job">' . h($jobNo) . '</td>'
        . '<td data-col="status">' . $statusBadge . '</td>'
        . '<td data-col="accountmanager">' . h($accountManager) . '</td>'
        . '<td data-col="description">' . h((string) ($line['Description'] ?? '')) . '</td>'
        . '<td data-col="planning_date">' . h(formatDate($planningDateRaw)) . '</td>'
        . '<td data-col="days">' . $badge . '</td>'
        . '<td data-col="qty" class="amount">' . h(number_format($qtyToInvoice, 2, ',', '.')) . '</td>'
        . '<td data-col="amount" class="amount">' . h(formatCurrency($lineAmount)) . '</td>'
        . '<td data-col="customer">' . h($customerNo) . '</td>'
        . '<td data-col="document_no">' . h((string) ($line['Document_No'] ?? '')) . '</td>'
        . '<td data-col="work_order">' . h((string) ($line['LVS_Work_Order_No'] ?? '')) . '</td>';

    if ($includeCompanyColumn) {
        $html .= '<td data-col="company">' . h($company) . '</td>';
    }

    return $html . '</tr>';
}
