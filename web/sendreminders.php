<?php

/**
 * Includes/requires
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/content/mail.php';
require_once __DIR__ . '/content/project_billing.php';
require_once __DIR__ . '/odata.php';

/**
 * Variabelen
 */

$today = date('Y-m-d');

/**
 * Page load
 */

header('Content-Type: application/json; charset=UTF-8');

// Valideer API-sleutel (uit header of querystring)
$incomingKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '');
if (!validateApiKey($incomingKey, $apiKeys)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Haal te factureren projectregels op
try {
    $pendingInvoiceLines = fetchPendingProjectInvoiceLines($baseUrl, $environment, $auth, $today);
} catch (Exception $e) {
    http_response_code(502);
    echo json_encode(['error' => 'OData ophalen mislukt: ' . $e->getMessage()]);
    exit;
}

if (empty($pendingInvoiceLines)) {
    echo json_encode([
        'sent' => false,
        'message' => LOC('reminder.no_invoices'),
        'count' => 0,
    ]);
    exit;
}

// Bouw e-mailinhoud op
$dateFormatted = date('d-m-Y');
$introText = LOC('reminder.intro', $dateFormatted);
$footerText = LOC('reminder.footer');
$subject = LOC('reminder.subject') . ' - ' . $dateFormatted;

$htmlRows = '';
$txtRows = '';
$totalAmount = 0.0;

foreach ($pendingInvoiceLines as $line) {
    $jobNo = h((string) ($line['Job_No'] ?? ''));
    $description = h((string) ($line['Description'] ?? ''));
    $planningDate = h(formatDate((string) ($line['Planning_Date'] ?? '')));
    $daysLate = daysOverdue((string) ($line['Planning_Date'] ?? ''));
    $qtyToInvoice = (float) ($line['Qty_to_Invoice'] ?? 0);
    $lineAmount = (float) ($line['Line_Amount'] ?? 0);
    $customerNo = h((string) (($line['LVS_Bill_to_Customer_No'] ?? '') !== '' ? ($line['LVS_Bill_to_Customer_No'] ?? '') : ($line['KVT_Bill_To_Cust_No_WO'] ?? '')));
    $documentNo = h((string) ($line['Document_No'] ?? ''));
    $workOrderNo = h((string) ($line['LVS_Work_Order_No'] ?? ''));
    $company = h((string) ($line['_company'] ?? ''));
    $amount = h(formatCurrency($lineAmount));
    $totalAmount += $lineAmount;

    $htmlRows .= '<tr>'
        . '<td>' . $jobNo . '</td>'
        . '<td>' . $description . '</td>'
        . '<td>' . $planningDate . '</td>'
        . '<td style="text-align:center"><strong>' . $daysLate . '</strong></td>'
        . '<td style="text-align:right">' . number_format($qtyToInvoice, 2, ',', '.') . '</td>'
        . '<td style="text-align:right">' . $amount . '</td>'
        . '<td>' . $customerNo . '</td>'
        . '<td>' . $documentNo . '</td>'
        . '<td>' . $workOrderNo . '</td>'
        . '<td>' . $company . '</td>'
        . '</tr>';

    $txtRows .= sprintf(
        "%-18s %-28s %-12s %4d %-12s %-15s %-14s %-18s %-12s %-20s\n",
        $line['Job_No'] ?? '',
        $line['Description'] ?? '',
        formatDate((string) ($line['Planning_Date'] ?? '')),
        $daysLate,
        number_format($qtyToInvoice, 2, ',', '.'),
        formatCurrency($lineAmount),
        ($line['LVS_Bill_to_Customer_No'] ?? '') !== '' ? ($line['LVS_Bill_to_Customer_No'] ?? '') : ($line['KVT_Bill_To_Cust_No_WO'] ?? ''),
        $line['Document_No'] ?? '',
        $line['LVS_Work_Order_No'] ?? '',
        $line['_company'] ?? ''
    );
}

$totalFormatted = h(formatCurrency($totalAmount));
$count = count($pendingInvoiceLines);

$bodyHtml = <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:20px">
  <h2 style="color:#1a2a44">{$subject}</h2>
  <p style="margin-bottom:16px">{$introText}</p>
  <table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;font-size:13px">
    <thead>
      <tr style="background:#1a2a44;color:#fff">
                <th style="text-align:left;padding:8px">Project</th>
                <th style="text-align:left;padding:8px">Omschrijving</th>
                <th style="text-align:left;padding:8px">Planningsdatum</th>
        <th style="text-align:center;padding:8px">Dagen te laat</th>
                <th style="text-align:right;padding:8px">Nog te factureren</th>
                <th style="text-align:right;padding:8px">Bedrag</th>
                <th style="text-align:left;padding:8px">Klant</th>
                <th style="text-align:left;padding:8px">Document</th>
                <th style="text-align:left;padding:8px">Werkorder</th>
                <th style="text-align:left;padding:8px">Bedrijf</th>
      </tr>
    </thead>
    <tbody>
      {$htmlRows}
      <tr style="background:#f0f0f0;font-weight:bold">
            <td colspan="5" style="padding:8px">Totaal ({$count})</td>
        <td style="text-align:right;padding:8px">{$totalFormatted}</td>
        <td></td>
            <td></td>
            <td></td>
            <td></td>
      </tr>
    </tbody>
  </table>
  <p style="margin-top:20px;font-size:11px;color:#888">{$footerText}</p>
</body>
</html>
HTML;

$bodyText = $introText . "\n\n";
$bodyText .= str_pad(LOC('table.job'), 18) . ' '
    . str_pad(LOC('table.description'), 28) . ' '
    . str_pad(LOC('table.planning_date'), 12) . ' '
    . str_pad(LOC('table.days_overdue'), 12) . ' '
    . str_pad(LOC('table.quantity_to_invoice'), 12) . ' '
    . str_pad(LOC('table.line_amount'), 15) . ' '
    . str_pad(LOC('table.customer'), 14) . ' '
    . str_pad(LOC('table.document_no'), 18) . ' '
    . str_pad(LOC('table.work_order'), 12) . ' '
    . LOC('table.company') . "\n";
$bodyText .= str_repeat('-', 170) . "\n";
$bodyText .= $txtRows;
$bodyText .= str_repeat('-', 170) . "\n";
$bodyText .= 'Totaal (' . $count . '): ' . formatCurrency($totalAmount) . "\n\n";
$bodyText .= $footerText . "\n";

// Verstuur naar alle geconfigureerde ontvangers
try {
    sendMail($reminderRecipients, $subject, $bodyHtml, $bodyText);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Versturen mislukt: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'sent' => true,
    'message' => LOC('reminder.success', count($reminderRecipients)),
    'count' => $count,
    'recipients' => $reminderRecipients,
]);
exit;
