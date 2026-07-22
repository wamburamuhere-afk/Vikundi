<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The M-Koba statement is 10 columns wide, so portrait A4 clips the right-hand
 * columns when printed. It now prints LANDSCAPE (only that statement — the
 * standard contributions statement stays portrait), and SOURCE / DESTINATION
 * drop Excel's ".00" so the phone / account numbers read cleanly.
 */
class MkobaStatementLandscapeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_statement.php';
    }

    public function testMkobaStatementPrintsLandscape(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/bms/customer/contribution_statement.php');
        // the landscape rule is gated behind the M-Koba layout only
        $this->assertMatchesRegularExpression('/if\s*\(\$isMkoba\).*?@page\s*\{\s*size:\s*A4\s+landscape/s', $src);
        $this->assertStringContainsString('id="mkobaPrintTable"', $src);
    }

    public function testStandardStatementStaysPortrait(): void
    {
        // No unconditional landscape @page rule (only the guarded one above).
        $src = file_get_contents(__DIR__ . '/../../app/bms/customer/contribution_statement.php');
        $landscapeCount = substr_count($src, 'size: A4 landscape');
        $this->assertSame(1, $landscapeCount, 'landscape should be declared exactly once (M-Koba only)');
    }

    public function testSourceAndDestinationDropExcelDotZero(): void
    {
        $row = vk_mkoba_statement_row([
            'mkoba_source'      => '255753778077.00',
            'mkoba_destination' => '60243499376.00',
            'mkoba_member_id_str' => '783459353',
            'amount' => 5000, 'contribution_date' => '2026-02-28',
        ], 1);
        $this->assertSame('255753778077', $row['SOURCE']);
        $this->assertSame('60243499376', $row['DESTINATION']);
    }
}
