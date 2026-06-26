<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards audit M1: the group operates in TZS only. The dashboard formatter must
 * delegate to the central helper (no hardcoded 'TZS ' symbol), and the group
 * currency selectors must not offer foreign currencies.
 */
class CurrencyNormalizationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testDashboardDelegatesToCentralFormatter(): void
    {
        $src = file_get_contents($this->root . '/app/dashboard.php');
        $this->assertStringContainsString("format_currency(\$n, 'TZS', 0)", $src,
            'dashboard fmt_currency must delegate to the central formatter');
        $this->assertStringNotContainsString("'TZS ' . number_format", $src,
            'dashboard must not hardcode the TZS symbol');
    }

    #[DataProvider('currencySelectorFiles')]
    public function testNoForeignCurrencyOffered(string $relPath): void
    {
        $src = file_get_contents($this->root . '/' . $relPath);
        foreach (['USD', 'KES', 'EUR', 'GBP', 'UGX'] as $code) {
            $this->assertStringNotContainsString("value=\"$code\"", $src,
                "$relPath must not offer $code as a selectable currency (audit M1)");
            $this->assertStringNotContainsString("'$code' =>", $src,
                "$relPath must not list $code in its currency map (audit M1)");
        }
        $this->assertStringContainsString('TZS', $src, "$relPath should still offer TZS");
    }

    public static function currencySelectorFiles(): array
    {
        return [
            ['app/constant/settings/system_settings.php'],
            ['app/bms/customer/group_settings.php'],
            ['app/bms/purchase/purchase_order_create.php'],
        ];
    }
}
