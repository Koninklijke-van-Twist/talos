<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../web/content/project_billing.php';
require_once __DIR__ . '/../web/content/department_access.php';
require_once __DIR__ . '/../web/content/billing_snapshot.php';

class BillingSnapshotTest extends TestCase
{
    private string $snapshotDir = '';
    private string $lockDir = '';

    protected function setUp(): void
    {
        $this->snapshotDir = sys_get_temp_dir() . '/talos_billing_snap_' . uniqid('', true);
        $this->lockDir = sys_get_temp_dir() . '/talos_billing_lock_' . uniqid('', true);
        mkdir($this->snapshotDir, 0777, true);
        mkdir($this->lockDir, 0777, true);

        $GLOBALS['TALOS_BILLING_SNAPSHOT_DIR_OVERRIDE'] = $this->snapshotDir;
        $GLOBALS['TALOS_BILLING_LOCK_DIR_OVERRIDE'] = $this->lockDir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TALOS_BILLING_SNAPSHOT_DIR_OVERRIDE'], $GLOBALS['TALOS_BILLING_LOCK_DIR_OVERRIDE']);

        foreach ([$this->snapshotDir, $this->lockDir] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function testMergeUpsertsAndRemovesIneligibleRows(): void
    {
        $existing = [
            [
                '_company' => 'KVT',
                'Job_No' => 'J1',
                'Line_No' => '10000',
                'Planning_Date' => '2026-01-01',
                'Qty_to_Invoice' => 1,
                'KVT_Status_Work_Order' => 'Open',
                'Description' => 'Keep me',
            ],
            [
                '_company' => 'KVT',
                'Job_No' => 'J2',
                'Line_No' => '10000',
                'Planning_Date' => '2026-01-02',
                'Qty_to_Invoice' => 2,
                'KVT_Status_Work_Order' => 'Open',
                'Description' => 'Will become zero',
            ],
        ];

        $incoming = [
            [
                '_company' => 'KVT',
                'Job_No' => 'J1',
                'Line_No' => '10000',
                'Planning_Date' => '2026-01-01',
                'Qty_to_Invoice' => 3,
                'KVT_Status_Work_Order' => 'Checked',
                'Description' => 'Updated',
            ],
            [
                '_company' => 'KVT',
                'Job_No' => 'J2',
                'Line_No' => '10000',
                'Planning_Date' => '2026-01-02',
                'Qty_to_Invoice' => 0,
                'KVT_Status_Work_Order' => 'Open',
                'Description' => 'Will become zero',
            ],
            [
                '_company' => 'KVT',
                'Job_No' => 'J3',
                'Line_No' => '10000',
                'Planning_Date' => '2026-02-01',
                'Qty_to_Invoice' => 1,
                'KVT_Status_Work_Order' => 'Planned',
                'Description' => 'New row',
            ],
        ];

        $merged = talosBillingMergeRows($existing, $incoming, false);

        $this->assertCount(2, $merged['rows']);
        $this->assertContains('KVT|J1|10000|2026-01-01', $merged['upserted']);
        $this->assertContains('KVT|J3|10000|2026-02-01', $merged['upserted']);
        $this->assertContains('KVT|J2|10000|2026-01-02', $merged['removed']);

        $keys = array_map('talosBillingRowKey', $merged['rows']);
        $this->assertContains('KVT|J1|10000|2026-01-01', $keys);
        $this->assertContains('KVT|J3|10000|2026-02-01', $keys);
        $this->assertNotContains('KVT|J2|10000|2026-01-02', $keys);
    }

    public function testChangeLogAndPatchesRespectDepartmentFilter(): void
    {
        $snapshot = talosBillingEmptySnapshot('KVT', 'env');
        $rows = [
            [
                '_company' => 'KVT',
                'Job_No' => 'A',
                'Line_No' => '1',
                'Planning_Date' => '2026-01-01',
                'Qty_to_Invoice' => 1,
                'KVT_Status_Work_Order' => 'Open',
                'Line_Amount' => 10,
                '_cost_center_code' => '65',
            ],
            [
                '_company' => 'KVT',
                'Job_No' => 'B',
                'Line_No' => '1',
                'Planning_Date' => '2026-01-02',
                'Qty_to_Invoice' => 1,
                'KVT_Status_Work_Order' => 'Open',
                'Line_Amount' => 20,
                '_cost_center_code' => '80',
            ],
        ];

        $snapshot['rows'] = $rows;
        $snapshot = talosBillingAppendChangeLog(
            $snapshot,
            ['KVT|A|1|2026-01-01', 'KVT|B|1|2026-01-02'],
            []
        );
        $this->assertSame(1, $snapshot['version']);
        $this->assertTrue(talosBillingSaveSnapshot($snapshot));

        $patchesAll = talosBillingLivePatches('KVT', 0, null, '2026-08-12');
        $this->assertCount(2, $patchesAll['upserted']);

        $patchesDept = talosBillingLivePatches('KVT', 0, ['65'], '2026-08-12');
        $this->assertCount(1, $patchesDept['upserted']);
        $this->assertSame('65', (string) ($patchesDept['upserted'][0]['_cost_center_code'] ?? ''));
        $this->assertSame('overdue', (string) ($patchesDept['upserted'][0]['_bucket'] ?? ''));
    }

    public function testCoalescedRefreshPiggybacksWhenLockHeld(): void
    {
        $company = 'Hunter van Twist';
        $lockPath = talosBillingLockPath($company);
        $handle = fopen($lockPath, 'c+');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        talosBillingWriteState($company, [
            'status' => 'running',
            'mode' => 'live',
            'version' => 7,
        ]);

        $result = talosBillingCoalescedRefresh($company, 'live', [
            'baseUrl' => 'https://example.invalid/',
            'auth' => ['mode' => 'basic', 'user' => 'x', 'pass' => 'y'],
            'today' => '2026-08-12',
            'companyEnvironmentMap' => [$company => 'env'],
            'activeEnvironments' => ['env'],
        ], [
            'non_blocking' => true,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['pending']);
        $this->assertTrue($result['coalesced']);
        $this->assertFalse($result['fetched']);
        $this->assertSame(7, (int) $result['version']);

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function testSplitRowsIntoBuckets(): void
    {
        $buckets = talosBillingSplitRowsIntoBuckets([
            ['Planning_Date' => '2026-08-01', 'Job_No' => '1'],
            ['Planning_Date' => '2026-08-20', 'Job_No' => '2'],
            ['Planning_Date' => '', 'Job_No' => '3'],
        ], '2026-08-12');

        $this->assertCount(1, $buckets['overdue']);
        $this->assertCount(1, $buckets['upcoming_month']);
        $this->assertSame('1', (string) $buckets['overdue'][0]['Job_No']);
        $this->assertSame('2', (string) $buckets['upcoming_month'][0]['Job_No']);
    }
}
