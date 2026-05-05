<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    // --- h() ---

    public function testHEscapesHtml(): void
    {
        $this->assertSame('&lt;b&gt;test&lt;/b&gt;', h('<b>test</b>'));
    }

    public function testHEscapesQuotes(): void
    {
        $this->assertSame('&quot;hello&quot;', h('"hello"'));
    }

    public function testHEmptyString(): void
    {
        $this->assertSame('', h(''));
    }

    // --- formatDate() ---

    public function testFormatDateIso(): void
    {
        $this->assertSame('28-04-2026', formatDate('2026-04-28'));
    }

    public function testFormatDateStripsTime(): void
    {
        $this->assertSame('01-01-2025', formatDate('2025-01-01T00:00:00'));
    }

    public function testFormatDateEmptyString(): void
    {
        $this->assertSame('', formatDate(''));
    }

    public function testFormatDateZeroDate(): void
    {
        $this->assertSame('', formatDate('0001-01-01'));
    }

    // --- formatCurrency() ---

    public function testFormatCurrencyPositive(): void
    {
        // Should contain '1.234,56' and a euro sign (3 bytes for €)
        $result = formatCurrency(1234.56);
        $this->assertStringContainsString('1.234,56', $result);
        $this->assertStringContainsString('€', $result);
    }

    public function testFormatCurrencyNegative(): void
    {
        $result = formatCurrency(-50.0);
        $this->assertStringContainsString('-', $result);
        $this->assertStringContainsString('50,00', $result);
    }

    public function testFormatCurrencyZero(): void
    {
        $result = formatCurrency(0.0);
        $this->assertStringContainsString('0,00', $result);
    }

    // --- daysOverdue() ---

    public function testDaysOverdueEmptyString(): void
    {
        $this->assertSame(0, daysOverdue(''));
    }

    public function testDaysOverdueZeroDate(): void
    {
        $this->assertSame(0, daysOverdue('0001-01-01'));
    }

    public function testDaysOverduePastDate(): void
    {
        // A date 10 days ago should return ~10
        $pastDate = date('Y-m-d', strtotime('-10 days'));
        $days = daysOverdue($pastDate);
        $this->assertSame(10, $days);
    }

    public function testDaysOverdueFutureDate(): void
    {
        // A future date should return 0 (not negative)
        $futureDate = date('Y-m-d', strtotime('+10 days'));
        $this->assertSame(0, daysOverdue($futureDate));
    }

    public function testDaysOverdueToday(): void
    {
        $this->assertSame(0, daysOverdue(date('Y-m-d')));
    }

    // --- validateApiKey() ---

    public function testValidateApiKeyValid(): void
    {
        $keys = ['alice' => 'key-abc', 'bob' => 'key-xyz'];
        $this->assertTrue(validateApiKey('key-abc', $keys));
        $this->assertTrue(validateApiKey('key-xyz', $keys));
    }

    public function testValidateApiKeyInvalid(): void
    {
        $keys = ['alice' => 'key-abc'];
        $this->assertFalse(validateApiKey('key-wrong', $keys));
    }

    public function testValidateApiKeyEmpty(): void
    {
        $keys = ['alice' => 'key-abc'];
        $this->assertFalse(validateApiKey('', $keys));
    }

    public function testValidateApiKeyEmptyKeyList(): void
    {
        $this->assertFalse(validateApiKey('key-abc', []));
    }

    // --- buildOdataCompanyUrl() ---

    public function testBuildOdataCompanyUrlEncodes(): void
    {
        $url = buildOdataCompanyUrl('https://host:7148/', 'env_aad', 'Koninklijke van Twist');
        $this->assertStringContainsString('Koninklijke%20van%20Twist', $url);
        $this->assertStringContainsString('/env_aad/ODataV4/Company%28%27', $url);
        $this->assertStringEndsWith('%27%29/', $url);
    }

    public function testBuildOdataCompanyUrlTrimsSlash(): void
    {
        $url1 = buildOdataCompanyUrl('https://host:7148/', 'env', 'Co');
        $url2 = buildOdataCompanyUrl('https://host:7148', 'env', 'Co');
        $this->assertSame($url1, $url2);
    }

    // --- buildOdataRootUrl() ---

    public function testBuildOdataRootUrlContainsEnvironment(): void
    {
        $url = buildOdataRootUrl('https://host:7148/', 'kvtmdlive_aad');
        $this->assertStringContainsString('kvtmdlive_aad', $url);
        $this->assertStringContainsString('/ODataV4/', $url);
    }

    public function testBuildOdataRootUrlTrimsSlash(): void
    {
        $url1 = buildOdataRootUrl('https://host:7148/', 'env');
        $url2 = buildOdataRootUrl('https://host:7148', 'env');
        $this->assertSame($url1, $url2);
    }

    // --- buildOdataMetadataUrl() ---

    public function testBuildOdataMetadataUrlEndsWithMetadata(): void
    {
        $url = buildOdataMetadataUrl('https://host:7148/', 'env');
        $this->assertStringEndsWith('/env/ODataV4/$metadata', $url);
    }

    public function testBuildOdataMetadataUrlTrimsSlash(): void
    {
        $url1 = buildOdataMetadataUrl('https://host:7148/', 'env');
        $url2 = buildOdataMetadataUrl('https://host:7148', 'env');
        $this->assertSame($url1, $url2);
    }

    // --- extractAccountManagerFromUserId() ---

    public function testExtractAccountManagerFromUserIdReturnsSuffixAfterBackslash(): void
    {
        $this->assertSame('CVRIJ', extractAccountManagerFromUserId('KVT\\CVRIJ'));
    }

    public function testExtractAccountManagerFromUserIdReturnsSuffixAfterSlash(): void
    {
        $this->assertSame('CVRIJ', extractAccountManagerFromUserId('KVT/CVRIJ'));
    }

    // --- renderInvoiceTableRow() ---

    public function testRenderInvoiceTableRowShowsParsedAccountManager(): void
    {
        $line = [
            'Planning_Date' => '2026-05-01',
            'Job_No' => 'J100',
            'Line_No' => '10000',
            'Qty_to_Invoice' => 1,
            'Line_Amount' => 10,
            'KVT_Status_Work_Order' => 'Open',
            'User_ID' => 'KVT\\CVRIJ',
            '_company' => 'KVT',
        ];

        $html = renderInvoiceTableRow($line, true, true, false);
        $this->assertStringContainsString('data-col="accountmanager">CVRIJ</td>', $html);
    }
}
