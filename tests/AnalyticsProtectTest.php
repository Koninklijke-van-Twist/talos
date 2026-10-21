<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class AnalyticsProtectTest extends TestCase
{
    public function testAnalyticsProtectScriptPasses(): void
    {
        $script = dirname(__DIR__) . '/tests/analytics_protect_test.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        $text = implode("\n", $output);

        $this->assertSame(0, $exitCode, $text);
        $this->assertStringContainsString('all passed', $text);
    }
}
