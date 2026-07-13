<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression: the Group Financial Ledger read as all-zeros because it filtered
 * contributions on status = 'confirmed', but this system approves contributions
 * to status 'approved'. The ledger must accept the same valid-contribution
 * status set the rest of the system uses ('confirmed', 'approved', '').
 */
class LedgerContributionStatusTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/financial_ledger.php');
    }

    public function testLedgerAcceptsApprovedContributions(): void
    {
        $this->assertStringContainsString(
            "status IN ('confirmed', 'approved', '')",
            $this->src,
            'Ledger must count approved contributions, not just confirmed.'
        );
    }

    public function testLedgerDoesNotFilterOnConfirmedOnly(): void
    {
        // the old, too-narrow filter that matched nothing must be gone
        $this->assertStringNotContainsString("WHERE status = 'confirmed'", $this->src);
    }
}
