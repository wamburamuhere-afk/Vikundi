<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Per-member Financial Statement. Guards the data-truth fix (Benefits Received
 * counts only approved/disbursed claims) and the print polish (landscape so the
 * wide month grid is legible, readable base font).
 */
class MemberStatementTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_statement.php');
    }

    public function testBenefitsReceivedCountsApprovedOnly(): void
    {
        // A paid claim is still an authorised claim ('paid' is a substate of
        // approved) — history includes both, but still excludes pending/rejected.
        $this->assertStringContainsString(
            "FROM death_expenses WHERE member_id = ? AND status IN ('approved','paid')",
            $this->src,
            'Benefits Received / history must include approved and paid claims but exclude pending or rejected ones.'
        );
    }

    public function testStatementPrintsLandscapeAndReadable(): void
    {
        // The print rules moved into includes/statement_layout.php when the statement
        // adopted the shared NSSF skeleton — all four statements print the same way,
        // so the rules live with the skeleton rather than being copied four times.
        $layout = file_get_contents(__DIR__ . '/../../includes/statement_layout.php');
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*size:\s*A4 landscape/', $layout);
        // the tiny print fonts are gone
        $this->assertStringNotContainsString('font-size: 10px', $layout);
        $this->assertStringNotContainsString('font-size: 7.5px', $layout);
        // Colour carries meaning in the grid (paid / partial / unpaid / before joining),
        // so it has to survive the printer rather than washing out to identical greys.
        $this->assertStringContainsString('print-color-adjust:exact', $layout);
    }

    public function testGridFlowsOntoPageOne(): void
    {
        $layout = file_get_contents(__DIR__ . '/../../includes/statement_layout.php');
        // the tall 2.2cm tfoot spacer that pushed the grid whole to page 2 is gone
        $this->assertStringNotContainsString('height: 2.2cm', $layout);
        $this->assertStringNotContainsString('height: 2.2cm', $this->src);
        // A year row must not be split across a page break — half a member's year on
        // one sheet and half on the next is unreadable at a meeting table.
        $this->assertStringContainsString('.vk-stmt-grid tr { page-break-inside:avoid; }', $layout);
    }
}
