<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('odata_get_all')) {
    function odata_get_all(string $url, array $auth, $ttlSeconds = 300): array
    {
        return [];
    }
}

require_once __DIR__ . '/../web/content/project_manager_scope.php';

class ProjectManagerScopeTest extends TestCase
{
    public function testSelectSingleCompanyEnvironmentMapUsesRequestedCompanyWhenPresent(): void
    {
        $map = [
            'Koninklijke van Twist' => 'kvtmdlive_aad',
            'Hunter van Twist' => 'kvtmdlive_aad',
        ];

        $selected = talosPmSelectSingleCompanyEnvironmentMap($map, 'Hunter van Twist');

        $this->assertSame(['Hunter van Twist' => 'kvtmdlive_aad'], $selected);
    }

    public function testSelectSingleCompanyEnvironmentMapFallsBackToOneCompany(): void
    {
        $map = [
            'Koninklijke van Twist' => 'kvtmdlive_aad',
            'Hunter van Twist' => 'kvtmdlive_aad',
        ];

        $selected = talosPmSelectSingleCompanyEnvironmentMap($map, '');

        $this->assertCount(1, $selected);
        $this->assertSame(['Hunter van Twist' => 'kvtmdlive_aad'], $selected);
    }

    public function testResolveCurrentUserFromUserSetupUsesSalespersonCardFields(): void
    {
        $rows = [
            [
                'Code' => 'SP001',
                'Name' => 'PM Alpha',
                'E_Mail' => 'auser@example.com',
                '_environment' => 'env1',
            ],
        ];

        $resolved = talosPmResolveCurrentUserFromUserSetup($rows, 'AUSER@example.com');

        $this->assertTrue($resolved['found']);
        $this->assertSame('auser@example.com', $resolved['email']);
        $this->assertSame('SP001', $resolved['user_id']);
        $this->assertSame('SP001', $resolved['project_manager']);
        $this->assertSame('PM Alpha', $resolved['project_manager_name']);
    }

    public function testAssignChildrenRejectsCircularDependency(): void
    {
        $assignments = [
            'Manager A' => ['Manager B'],
            'Manager B' => ['Manager C'],
        ];

        $result = talosPmAssignChildren($assignments, 'Manager C', ['Manager A']);

        $this->assertFalse($result['ok']);
        $this->assertSame('pm_admin.error.circular_dependency', $result['error_key']);
    }

    public function testGetAllowedProjectManagersReturnsSelfAndDescendants(): void
    {
        $assignments = [
            'Manager A' => ['Manager B'],
            'Manager B' => ['Manager C'],
            'Manager D' => ['Manager E'],
        ];

        $allowed = talosPmGetAllowedProjectManagers($assignments, 'Manager A');

        $this->assertSame(['Manager A', 'Manager B', 'Manager C'], $allowed);
    }

    public function testFilterRowsByAllowedManagersIsCaseInsensitive(): void
    {
        $rows = [
            ['_project_manager' => 'Manager A', 'Line_No' => 1],
            ['_project_manager' => 'manager b', 'Line_No' => 2],
            ['_project_manager' => 'Manager C', 'Line_No' => 3],
        ];

        $filtered = talosPmFilterRowsByAllowedManagers($rows, ['MANAGER A', 'Manager B']);

        $this->assertCount(2, $filtered);
        $this->assertSame(1, (int) $filtered[0]['Line_No']);
        $this->assertSame(2, (int) $filtered[1]['Line_No']);
    }

    public function testMapLegacyManagerCodeToSalespersonCodeUsesEmailLocalPart(): void
    {
        $rows = [
            [
                'Code' => 'SP-001',
                'E_Mail' => 'john.doe@domein.nl',
            ],
            [
                'Code' => 'SP-002',
                'E_Mail' => 'jane.smith@domein.nl',
            ],
        ];

        $mapped = talosPmMapLegacyManagerCodeToSalespersonCode('KVT\\john.doe', $rows);
        $this->assertSame('SP-001', $mapped);

        $mappedSlash = talosPmMapLegacyManagerCodeToSalespersonCode('KVT/john.doe', $rows);
        $this->assertSame('SP-001', $mappedSlash);
    }

    public function testRemapLegacyManagerCodesInBucketsReplacesLegacyValues(): void
    {
        $rows = [
            [
                'Code' => 'SP-001',
                'E_Mail' => 'john.doe@domein.nl',
            ],
        ];

        $buckets = [
            'overdue' => [
                ['_project_manager' => 'KVT\\john.doe'],
            ],
            'upcoming_month' => [],
            'upcoming_year' => [],
            'all' => [
                ['_project_manager' => 'SP-001'],
                ['_project_manager' => 'KVT\\unknown.user'],
            ],
        ];

        $mappedBuckets = talosPmRemapLegacyManagerCodesInBuckets($buckets, $rows);

        $this->assertSame('SP-001', (string) $mappedBuckets['overdue'][0]['_project_manager']);
        $this->assertSame('SP-001', (string) $mappedBuckets['all'][0]['_project_manager']);
        $this->assertSame('KVT\\unknown.user', (string) $mappedBuckets['all'][1]['_project_manager']);
    }

    public function testResolveCreatedByDisplayNameMapsLegacyAndEmailFormatsToSalespersonName(): void
    {
        $rows = [
            [
                'Code' => 'CVRIJ',
                'Name' => 'Chris Vrij',
                'E_Mail' => 'cvrij@kvt.nl',
            ],
        ];

        $this->assertSame('Chris Vrij', talosPmResolveCreatedByDisplayName('KVT\\CVRIJ', $rows));
        $this->assertSame('Chris Vrij', talosPmResolveCreatedByDisplayName('cvrij@kvt.nl', $rows));
        $this->assertSame('unknown.user', talosPmResolveCreatedByDisplayName('KVT\\unknown.user', $rows));
    }

    public function testApplyCreatedByDisplayNamesToBucketsAddsDisplayField(): void
    {
        $rows = [
            [
                'Code' => 'ADGROOT',
                'Name' => 'A. de Groot',
                'E_Mail' => 'adgroot@kvt.nl',
            ],
        ];

        $buckets = [
            'overdue' => [
                ['User_ID' => 'KVT\\ADGROOT'],
            ],
            'upcoming_month' => [],
            'upcoming_year' => [],
            'all' => [
                ['User_ID' => 'ADGROOT'],
            ],
        ];

        $enriched = talosPmApplyCreatedByDisplayNamesToBuckets($buckets, $rows);

        $this->assertSame('A. de Groot', (string) $enriched['overdue'][0]['_created_by_display']);
        $this->assertSame('A. de Groot', (string) $enriched['all'][0]['_created_by_display']);
    }
}
