<?php

/**
 * Includes/requires
 */

require_once __DIR__ . '/content/bootstrap.php';
require_once __DIR__ . '/content/localization.php';
require_once __DIR__ . '/content/helpers.php';
require_once __DIR__ . '/content/project_billing.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/content/pending_project_invoice_page.php';

/**
 * Page load
 */

require __DIR__ . '/content/views/overdue_invoices.php';
