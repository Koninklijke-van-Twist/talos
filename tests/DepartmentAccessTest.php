<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../web/content/department_access.php';

class DepartmentAccessTest extends TestCase
{
    private string $tempPath = '';

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . '/talos_department_access_' . uniqid('', true) . '.json';
        if (!defined('TALOS_DEPARTMENT_ACCESS_PATH_OVERRIDE')) {
            // Path is a const; tests use normalize/group helpers and a temp file via save/load wrappers below.
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempPath !== '' && is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }

    public function testNormalizeDepartmentCodesSortsAndDedupesOrderIndependently(): void
    {
        $this->assertSame(['50', '65', '80'], talosNormalizeDepartmentCodes(['80', '50', '65', '50']));
        $this->assertSame(
            talosDepartmentSetKey(['50', '65', '80']),
            talosDepartmentSetKey(['65', '80', '50'])
        );
    }

    public function testNormalizeAccessPayloadDropsEmptyUsers(): void
    {
        $payload = talosNormalizeDepartmentAccessPayload([
            'users' => [
                'A@KVT.NL' => ['65', '50'],
                'empty@kvt.nl' => [],
                'invalid' => 'nope',
            ],
        ]);

        $this->assertSame(['a@kvt.nl' => ['50', '65']], $payload['users']);
    }

    public function testGroupUsersByDepartmentSetSortsByCountThenSum(): void
    {
        $groups = talosGroupUsersByDepartmentSet([
            'one@kvt.nl' => ['15'],
            'two@kvt.nl' => ['50', '65'],
            'three@kvt.nl' => ['80', '50', '65'],
            'four@kvt.nl' => ['65', '50'],
            'gone@kvt.nl' => [],
        ]);

        $this->assertCount(3, $groups);
        $this->assertSame(['50', '65', '80'], $groups[0]['departments']);
        $this->assertSame(['three@kvt.nl'], array_column($groups[0]['users'], 'email'));
        $this->assertSame(['50', '65'], $groups[1]['departments']);
        $this->assertSame(['four@kvt.nl', 'two@kvt.nl'], array_column($groups[1]['users'], 'email'));
        $this->assertSame(['15'], $groups[2]['departments']);
    }

    public function testFilterRowsByAllowedDepartments(): void
    {
        $rows = [
            ['_cost_center_code' => '50', 'Job_No' => 'A'],
            ['_cost_center_code' => '65', 'Job_No' => 'B'],
            ['_cost_center_code' => '', 'Job_No' => 'C'],
            ['_cost_center_code' => '80', 'Job_No' => 'D'],
        ];

        $filtered = talosFilterRowsByAllowedDepartments($rows, ['65', '80']);
        $this->assertSame(['B', 'D'], array_column($filtered, 'Job_No'));

        $all = talosFilterRowsByAllowedDepartments($rows, null);
        $this->assertCount(4, $all);

        $none = talosFilterRowsByAllowedDepartments($rows, []);
        $this->assertSame([], $none);
    }

    public function testIsIctUserEmailMatchesCaseInsensitive(): void
    {
        $this->assertTrue(talosIsIctUserEmail('TFALKEN@KVT.NL', ['tfalken@kvt.nl']));
        $this->assertFalse(talosIsIctUserEmail('someone@kvt.nl', ['tfalken@kvt.nl']));
    }
}
