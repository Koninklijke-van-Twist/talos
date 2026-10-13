<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class InlineScriptSyntaxTest extends TestCase
{
    public function testRenderedAdminPageWithSapImportsParses(): void
    {
        $html = $this->renderOverdueInvoices([
            'company' => 'Koninklijke van Twist',
            'hide_sap_imports' => '1',
            'project_manager_map' => ['PM' => 'Project Manager'],
        ]);

        $this->assertStringNotContainsString('<b>Warning</b>', $html);
        $this->assertStringNotContainsString('<b>Notice</b>', $html);
        $this->assertStringNotContainsString('Fatal error', $html);
        $this->assertStringContainsString('hideSapImports: true', $html);
        $this->assertStringContainsString('company: "Koninklijke van Twist"', $html);
        $this->assertStringContainsString('async function saveUserDepartments', $html);

        $scripts = $this->inlineScripts($html);
        $this->assertCount(2, $scripts);
        foreach ($scripts as $index => $script) {
            $this->assertNodeCheck($script, 'script ' . $index);
        }
    }

    public function testRenderedScriptSurvivesJsonFailureAndScriptBreakout(): void
    {
        $html = $this->renderOverdueInvoices([
            'company' => "Konin\xC3\x28</script>",
            'hide_sap_imports' => '1',
            'project_manager_map' => ['PM' => INF],
        ]);

        $this->assertStringNotContainsString('<b>Warning</b>', $html);
        $this->assertStringNotContainsString('</script><script>', $html);
        $this->assertStringContainsString('hideSapImports: true', $html);
        $this->assertStringContainsString('pmDisplayMap: {}', $html);
        $this->assertStringContainsString('\\u003C', $html);

        foreach ($this->inlineScripts($html) as $index => $script) {
            $this->assertNodeCheck($script, 'script ' . $index);
            $this->assertStringNotContainsString('</script>', $script);
        }
    }

    public function testInlineScriptContextHidesWarningsFromTheScript(): void
    {
        $helper = dirname(__DIR__) . '/web/content/helpers.php';
        $source = <<<'PHP'
<?php
require %s;
ini_set('display_errors', '1');
ini_set('html_errors', '1');
ini_set('log_errors', '0');
error_reporting(E_ALL);
beginInlineScript();
echo '<script>';
trigger_error('sap-import-warning', E_USER_WARNING);
echo 'var ok = ';
echo jsJson(INF);
echo ';</script>';
endInlineScript();
PHP;
        $path = tempnam(sys_get_temp_dir(), 'talos-script-');
        $stdoutFile = tempnam(sys_get_temp_dir(), 'talos-stdout-');
        $this->assertNotFalse($path);
        $this->assertNotFalse($stdoutFile);
        file_put_contents($path, sprintf($source, var_export($helper, true)));

        $code = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path)
            . ' > ' . escapeshellarg($stdoutFile) . ' 2>/dev/null',
            $unused,
            $code
        );
        $stdout = (string) file_get_contents($stdoutFile);
        @unlink($path);
        @unlink($stdoutFile);

        $this->assertSame(0, $code, $stdout);
        $this->assertSame('<script>var ok = null;</script>', $stdout);
    }

    /**
     * @param array{company: string, hide_sap_imports: string, project_manager_map: array<string, mixed>} $options
     */
    private function renderOverdueInvoices(array $options): string
    {
        $_GET = [
            'company' => $options['company'],
            'hide_sap_imports' => $options['hide_sap_imports'],
        ];
        $_SESSION['user'] = [
            'email' => 'localtester@kvt.nl',
            'admin' => true,
        ];
        $_SESSION['lang'] = 'nl';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        require_once dirname(__DIR__) . '/web/content/project_manager_scope.php';

        // Zelfde regel als pending_project_invoice_page.php: alleen "0" toont SAP-imports.
        $hideSapImports = !isset($_GET['hide_sap_imports']) || (string) $_GET['hide_sap_imports'] !== '0';
        $selectedCompany = trim((string) ($_GET['company'] ?? ''));
        $isAdminUser = true;
        $showOdataErrorDetails = false;
        $debugFetchAllRules = false;
        $debugCompanyResults = [];
        $requiresCompanySelection = false;
        $odataError = null;
        $odataErrorPublic = null;
        $availableCompanies = [$selectedCompany];
        $pendingInvoiceLines = [[
            'Planning_Date' => '2026-01-01',
            'Job_No' => 'J1',
            'Line_No' => '10000',
            'Qty_to_Invoice' => 1,
            'Line_Amount' => 12.5,
            'KVT_Status_Work_Order' => 'Open',
            'User_ID' => 'KVT\\CVRIJ',
            'Description' => 'SAP-regel',
            'Document_No' => 'D1',
            'LVS_Work_Order_No' => 'WO-1',
            '_company' => $selectedCompany,
            '_project_manager' => 'PM',
            '_project_manager_display' => 'Project Manager',
            '_cost_center_code' => '100',
            '_jobcard_status' => 'Released',
            '_created_by_display' => 'CVRIJ',
        ]];
        $upcomingInvoiceLines = [];
        $upcomingSectionTitle = 'Binnenkort';
        $currentPage = 1;
        $isPartialResult = true;
        $ictUsers = ['localtester@kvt.nl'];
        $projectManagerDefaultSelection = 'PM';
        $projectManagerFilterDefaultSelection = 'PM';
        $allowedProjectManagers = ['PM'];
        $allProjectManagers = ['PM'];
        $projectManagerDisplayMap = $options['project_manager_map'];
        $currentUserSetup = ['project_manager_name' => 'Project Manager'];

        $previousDisplayErrors = ini_get('display_errors');
        $previousHtmlErrors = ini_get('html_errors');
        ini_set('display_errors', '1');
        ini_set('html_errors', '1');

        ob_start();
        try {
            require dirname(__DIR__) . '/web/content/views/overdue_invoices.php';
            $html = (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        } finally {
            ini_set('display_errors', (string) $previousDisplayErrors);
            ini_set('html_errors', (string) $previousHtmlErrors);
        }

        return $html;
    }

    /**
     * @return list<string>
     */
    private function inlineScripts(string $html): array
    {
        $matched = preg_match_all('#<script>(.*?)</script>#s', $html, $matches);
        $this->assertNotFalse($matched);
        $this->assertGreaterThan(0, $matched);

        return array_values($matches[1]);
    }

    private function assertNodeCheck(string $script, string $label): void
    {
        $node = $this->nodeBinary();
        $path = tempnam(sys_get_temp_dir(), 'talos-js-');
        $this->assertNotFalse($path);
        file_put_contents($path, $script);

        $output = [];
        $code = 0;
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($path) . ' 2>&1', $output, $code);
        @unlink($path);

        $this->assertSame(0, $code, $label . " faalt op node --check:\n" . implode("\n", $output));
    }

    private function nodeBinary(): string
    {
        $fromEnv = getenv('NODE_BINARY');
        if (is_string($fromEnv) && $fromEnv !== '' && is_executable($fromEnv)) {
            return $fromEnv;
        }

        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        foreach ($paths as $dir) {
            $candidate = rtrim($dir, '/') . '/node';
            if ($candidate !== '/node' && is_executable($candidate)) {
                return $candidate;
            }
        }

        foreach (['/exec-daemon/node', '/usr/local/bin/node', '/usr/bin/node'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $this->fail('node is vereist voor de syntaxcheck van inline scripts');
    }
}
