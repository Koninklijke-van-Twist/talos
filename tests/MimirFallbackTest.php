<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MimirFallbackTest extends TestCase
{
    public function testMimirFallbackScript(): void
    {
        $script = __DIR__ . '/mimir_fallback_test.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }
}
