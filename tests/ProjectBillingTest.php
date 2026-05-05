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
        $this->assertStringContainsString('(No eq 800000 or No eq 800001)', $filter);

        $this->assertStringContainsString('?$filter=', $url);
        $this->assertStringContainsString('&$select=', $url);
        $selectPart = explode('&$select=', $url, 2);
        $this->assertCount(2, $selectPart);

        $selectValue = explode('&', (string) $selectPart[1], 2)[0];
        $this->assertStringContainsString('User_ID', $selectValue);
    }
}
