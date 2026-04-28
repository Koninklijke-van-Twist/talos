<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

class LocalizationTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset session lang to default
        $_SESSION['lang'] = 'nl';
    }

    public function testLocReturnsNlByDefault(): void
    {
        $result = LOC('page.overdue_invoices.title');
        $this->assertSame('Talos', $result);
    }

    public function testLocReturnsEnglish(): void
    {
        $_SESSION['lang'] = 'en';
        $result = LOC('page.overdue_invoices.title');
        $this->assertSame('Talos', $result);
    }

    public function testLocReturnsDeutsch(): void
    {
        $_SESSION['lang'] = 'de';
        $result = LOC('page.overdue_invoices.title');
        $this->assertSame('Talos', $result);
    }

    public function testLocReturnsFrench(): void
    {
        $_SESSION['lang'] = 'fr';
        $result = LOC('page.overdue_invoices.title');
        $this->assertSame('Talos', $result);
    }

    public function testLocFallsBackToNlForUnknownLang(): void
    {
        $_SESSION['lang'] = 'xx';
        $result = LOC('page.overdue_invoices.title');
        $this->assertSame('Talos', $result);
    }

    public function testLocReturnsKeyForMissingTranslation(): void
    {
        $_SESSION['lang'] = 'nl';
        $result = LOC('nonexistent.key');
        $this->assertSame('nonexistent.key', $result);
    }

    public function testLocFormatsSprintfArgs(): void
    {
        $_SESSION['lang'] = 'nl';
        $result = LOC('reminder.success', 3);
        $this->assertStringContainsString('3', $result);
    }

    public function testAllKeysExistInAllLanguages(): void
    {
        $languages = ['nl', 'en', 'de', 'fr'];
        $nlKeys = array_keys(TRANSLATIONS['nl']);

        foreach ($languages as $lang) {
            foreach ($nlKeys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    TRANSLATIONS[$lang],
                    "Sleutel '$key' ontbreekt in taal '$lang'"
                );
            }
        }
    }
}
