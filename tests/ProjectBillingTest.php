<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('odata_get_all')) {
    function odata_get_all(string $url, array $auth, $ttlSeconds = 300): array
    {
        $GLOBALS['__projectBillingTestLastUrl'] = $url;

        if (isset($GLOBALS['__projectBillingTestOdataResponder']) && is_callable($GLOBALS['__projectBillingTestOdataResponder'])) {
            return (array) call_user_func($GLOBALS['__projectBillingTestOdataResponder'], $url, $auth, $ttlSeconds);
        }

        return [];
    }
}

require_once __DIR__ . '/../web/content/project_billing.php';

class ProjectBillingTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['__projectBillingTestLastUrl']);
        unset($GLOBALS['__projectBillingTestOdataResponder']);
        unset($GLOBALS['__projectBillingTestUrls']);
    }

    public function testFetchRowsEnrichesProjectCostCenterCodeFromAppProjecten(): void
    {
        $GLOBALS['__projectBillingTestUrls'] = [];
        $GLOBALS['__projectBillingTestOdataResponder'] = static function (string $url): array {
            $GLOBALS['__projectBillingTestUrls'][] = $url;

            if (str_contains($url, 'FactureerbareProjectPlanningsRegels')) {
                return [
                    [
                        'Job_No' => 'JOB-001',
                        'Line_No' => 10,
                        'Planning_Date' => '2026-01-15',
                        'Description' => 'Test line',
                        'Document_No' => 'DOC-1',
                        'Qty_to_Invoice' => 1,
                        'Line_Amount' => 100,
                        'User_ID' => 'user@example.com',
                        'KVT_Status_Work_Order' => 'Open',
                    ]
                ];
            }

            if (str_contains($url, 'Projecten')) {
                return [
                    [
                        'No' => 'JOB-001',
                        'KVT_Sales_Person_Code' => 'SP-01',
                        'Project_Manager' => 'PM-01',
                        'LVS_Global_Dimension_1_Code' => 'CC-42',
                    ]
                ];
            }

            return [];
        };

        $debugCompanyResults = [];
        $firstErrorMessage = null;
        $callCount = 0;
        $limitReached = false;

        $rows = mergeCompanyRowsForWindow(
            ['Company'],
            ['Company' => 'env'],
            'https://example.test',
            ['env'],
            ['username' => 'u', 'password' => 'p'],
            '2026-01-01',
            '2026-01-31',
            false,
            0,
            false,
            $debugCompanyResults,
            $firstErrorMessage,
            $callCount,
            $limitReached
        );

        $this->assertCount(1, $rows);
        $this->assertSame('SP-01', (string) $rows[0]['_project_manager']);
        $this->assertSame('CC-42', (string) $rows[0]['_cost_center_code']);

        $urls = (array) ($GLOBALS['__projectBillingTestUrls'] ?? []);
        $projectQueryUrl = '';
        foreach ($urls as $url) {
            if (str_contains($url, 'Projecten')) {
                $projectQueryUrl = (string) $url;
                break;
            }
        }

        $this->assertNotSame('', $projectQueryUrl);
        $this->assertStringContainsString('LVS_Global_Dimension_1_Code', rawurldecode($projectQueryUrl));
        $this->assertStringContainsString('KVT_Sales_Person_Code', rawurldecode($projectQueryUrl));
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
        $this->assertStringContainsString('Qty_to_Invoice gt 0', $filter);
        $this->assertStringContainsString('Line_Amount gt 0', $filter);
        $this->assertStringContainsString(
            "(KVT_Status_Work_Order eq 'Open' or KVT_Status_Work_Order eq 'Planned' or KVT_Status_Work_Order eq 'Checked')",
            $filter
        );

        $this->assertStringContainsString('?$filter=', $url);
        $this->assertStringContainsString('&$select=', $url);
        $this->assertStringNotContainsString('$skip=', $url);
        $this->assertStringNotContainsString('$top=', $url);
        $selectPart = explode('&$select=', $url, 2);
        $this->assertCount(2, $selectPart);

        $selectValue = explode('&', (string) $selectPart[1], 2)[0];
        $this->assertStringContainsString('User_ID', $selectValue);
    }

    public function testMergeDropsZeroAmountLinesAndCompletedProjects(): void
    {
        $GLOBALS['__projectBillingTestOdataResponder'] = static function (string $url): array {
            if (str_contains($url, 'FactureerbareProjectPlanningsRegels')) {
                return [
                    [
                        'Job_No' => '164144',
                        'Line_No' => 10,
                        'Planning_Date' => '2026-02-01',
                        'Description' => 'Overboeking',
                        'Qty_to_Invoice' => 1,
                        'Line_Amount' => 0,
                        'KVT_Status_Work_Order' => 'Open',
                    ],
                    [
                        'Job_No' => '164144',
                        'Line_No' => 20,
                        'Planning_Date' => '2026-02-02',
                        'Description' => 'Overboeking nul',
                        'Qty_to_Invoice' => 2,
                        'Line_Amount' => '0.00',
                        'KVT_Status_Work_Order' => 'Checked',
                    ],
                    [
                        'Job_No' => '200001',
                        'Line_No' => 10,
                        'Planning_Date' => '2026-02-03',
                        'Description' => 'Voltooid project',
                        'Qty_to_Invoice' => 1,
                        'Line_Amount' => 50,
                        'KVT_Status_Work_Order' => 'Planned',
                    ],
                    [
                        'Job_No' => '200002',
                        'Line_No' => 10,
                        'Planning_Date' => '2026-02-04',
                        'Description' => 'Completed project',
                        'Qty_to_Invoice' => 1,
                        'Line_Amount' => 75,
                        'KVT_Status_Work_Order' => 'Open',
                    ],
                    [
                        'Job_No' => '300001',
                        'Line_No' => 10,
                        'Planning_Date' => '2026-02-05',
                        'Description' => 'Open project',
                        'Qty_to_Invoice' => 3,
                        'Line_Amount' => 120.5,
                        'KVT_Status_Work_Order' => 'Open',
                    ],
                    [
                        'Job_No' => '300002',
                        'Line_No' => 10,
                        'Planning_Date' => '2026-02-06',
                        'Description' => 'Credit',
                        'Qty_to_Invoice' => 1,
                        'Line_Amount' => -10,
                        'KVT_Status_Work_Order' => 'Open',
                    ],
                ];
            }

            if (str_contains($url, 'Projecten')) {
                return [
                    [
                        'No' => '164144',
                        'Status' => 'Voltooid',
                        'KVT_Sales_Person_Code' => 'SP',
                        'Project_Manager' => '',
                        'LVS_Global_Dimension_1_Code' => 'CC',
                    ],
                    [
                        'No' => '200001',
                        'Status' => ' voltooid ',
                        'KVT_Sales_Person_Code' => 'SP',
                        'Project_Manager' => '',
                        'LVS_Global_Dimension_1_Code' => 'CC',
                    ],
                    [
                        'No' => '200002',
                        'Status' => 'Completed',
                        'KVT_Sales_Person_Code' => 'SP',
                        'Project_Manager' => '',
                        'LVS_Global_Dimension_1_Code' => 'CC',
                    ],
                    [
                        'No' => '300001',
                        'Status' => 'Open',
                        'KVT_Sales_Person_Code' => 'SP',
                        'Project_Manager' => '',
                        'LVS_Global_Dimension_1_Code' => 'CC',
                    ],
                    [
                        'No' => '300002',
                        'Status' => 'Open',
                        'KVT_Sales_Person_Code' => 'SP',
                        'Project_Manager' => '',
                        'LVS_Global_Dimension_1_Code' => 'CC',
                    ],
                ];
            }

            return [];
        };

        $debugCompanyResults = [];
        $firstErrorMessage = null;
        $callCount = 0;
        $limitReached = false;

        $rows = mergeCompanyRowsForWindow(
            ['Vitens'],
            ['Vitens' => 'env'],
            'https://example.test',
            ['env'],
            ['username' => 'u', 'password' => 'p'],
            null,
            null,
            false,
            0,
            true,
            $debugCompanyResults,
            $firstErrorMessage,
            $callCount,
            $limitReached
        );

        $this->assertCount(1, $rows);
        $this->assertSame('300001', (string) $rows[0]['Job_No']);
        $this->assertSame('Open', (string) $rows[0]['_jobcard_status']);
        $this->assertSame(120.5, (float) $rows[0]['Line_Amount']);
        $this->assertSame(1, (int) $debugCompanyResults['Vitens']['count']);
    }

    public function testCompletedProjectStatusMatchesDisplayedValuesOnly(): void
    {
        $this->assertTrue(isCompletedProjectStatus('Completed'));
        $this->assertTrue(isCompletedProjectStatus('voltooid'));
        $this->assertTrue(isCompletedProjectStatus(' Voltooid '));
        $this->assertFalse(isCompletedProjectStatus('Open'));
        $this->assertFalse(isCompletedProjectStatus('Planning'));
        $this->assertFalse(isCompletedProjectStatus('Quote'));
        $this->assertFalse(isCompletedProjectStatus('Offerte'));
        $this->assertFalse(isCompletedProjectStatus(''));
        $this->assertFalse(isCompletedProjectStatus('afgesloten'));
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

    public function testFetchAvailableCompanyContextBuildsEnvironmentMapAcrossEnvironments(): void
    {
        $GLOBALS['__projectBillingTestOdataResponder'] = static function (string $url): array {
            if (str_contains($url, '/kvtmdlive_aad/ODataV4/Company?')) {
                return [
                    ['Name' => 'Alpha NL'],
                    ['Name' => 'Zeta NL'],
                ];
            }

            if (str_contains($url, '/kvtgermanylive_aad/ODataV4/Company?')) {
                return [
                    ['Name' => 'Beta DE'],
                ];
            }

            return [];
        };

        $context = fetchAvailableCompanyContext(
            'https://example.test',
            ['kvtmdlive_aad', 'kvtgermanylive_aad'],
            ['user' => 'u', 'pass' => 'p']
        );

        $this->assertSame(['Alpha NL', 'Beta DE', 'Zeta NL'], $context['available_companies']);
        $this->assertSame('kvtmdlive_aad', $context['company_environment_map']['Alpha NL']);
        $this->assertSame('kvtgermanylive_aad', $context['company_environment_map']['Beta DE']);
    }

    public function testFetchAvailableCompanyContextThrowsOnCrossEnvironmentOverlap(): void
    {
        $GLOBALS['__projectBillingTestOdataResponder'] = static function (string $url): array {
            if (str_contains($url, '/kvtmdlive_aad/ODataV4/Company?')) {
                return [
                    ['Name' => 'Shared Company'],
                ];
            }

            if (str_contains($url, '/kvtgermanylive_aad/ODataV4/Company?')) {
                return [
                    ['Name' => 'Shared Company'],
                ];
            }

            return [];
        };

        $this->expectException(Exception::class);
        $this->expectExceptionCode(40901);
        $this->expectExceptionMessage('Shared Company');

        fetchAvailableCompanyContext(
            'https://example.test',
            ['kvtmdlive_aad', 'kvtgermanylive_aad'],
            ['user' => 'u', 'pass' => 'p']
        );
    }
}
