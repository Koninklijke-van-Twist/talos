<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('odata_get_all')) {
    function odata_get_all(string $url, array $auth, $ttlSeconds = 300): array
    {
        $GLOBALS['__projectBillingTestLastUrl'] = $url;

        return [];
    }
}

require_once __DIR__ . '/../web/content/project_billing.php';

class ProjectBillingTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['__projectBillingTestLastUrl']);
    }

    public function testFetchRowsAddsNoFilterForSpecificRuleTypes(): void
    {
        fetchProjectInvoiceRowsForCompanyWindow(
            'https://example.test',
            'env',
            ['username' => 'u', 'password' => 'p'],
            'Company',
            '2026-01-01',
            '2026-01-31',
            false,
            0,
            5
        );

        $url = (string) ($GLOBALS['__projectBillingTestLastUrl'] ?? '');
        $this->assertNotSame('', $url);

        $matches = [];
        preg_match('/[?&]\$filter=([^&]+)/', $url, $matches);
        $this->assertArrayHasKey(1, $matches);

        $filter = rawurldecode((string) $matches[1]);
        $this->assertStringContainsString("(No eq '800000' or No eq '800001')", $filter);

        $this->assertStringContainsString('?$filter=', $url);
        $this->assertStringContainsString('&$select=', $url);
        $selectPart = explode('&$select=', $url, 2);
        $this->assertCount(2, $selectPart);

        $selectValue = explode('&', (string) $selectPart[1], 2)[0];
        $this->assertStringContainsString('User_ID', $selectValue);
    }

    public function testIsSapImportDescriptionMatchesRequiredPattern(): void
    {
        $this->assertTrue(isSapImportDescription('IMPORT SAP iets JAAR 2026'));
        $this->assertFalse(isSapImportDescription('import sap iets JAAR 2026'));
        $this->assertFalse(isSapImportDescription('IMPORT SAP iets JAAR ABCD'));
        $this->assertFalse(isSapImportDescription('IMPORT SAP iets JAAR 2026 extra'));
    }

    public function testFilterSapImportRowsOnlyRemovesMatchingRowsWhenEnabled(): void
    {
        $rows = [
            ['Description' => 'IMPORT SAP order JAAR 2024'],
            ['Description' => 'IMPORT SAP order JAAR 2024 EXTRA'],
            ['Description' => 'Reguliere regel'],
        ];

        $filtered = filterSapImportRows($rows, true);
        $this->assertCount(2, $filtered);
        $this->assertSame('IMPORT SAP order JAAR 2024 EXTRA', (string) $filtered[0]['Description']);
        $this->assertSame('Reguliere regel', (string) $filtered[1]['Description']);

        $unfiltered = filterSapImportRows($rows, false);
        $this->assertCount(3, $unfiltered);
    }
}
