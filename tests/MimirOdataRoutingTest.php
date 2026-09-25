<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MimirOdataRoutingTest extends TestCase
{
    public function testMimirRoutingScript(): void
    {
        $script = __DIR__ . '/mimir_odata_routing.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('all mimir routing tests passed', implode("\n", $output));
    }
}
