<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The dashboard scanned death_expenses and general_expenses TWICE each — once for the
 * pending count, once for the approved/paid total. Each table is now read in a single
 * pass that returns both figures; the KPI totals read from those rows.
 */
class DashboardExpenseOnePassTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/dashboard.php');
    }

    public function testEachExpenseTableIsReadInOnePass(): void
    {
        // One combined query per table returns pending count + authorised sum.
        $this->assertStringContainsString('FROM death_expenses")', $this->src);
        $this->assertStringContainsString('FROM general_expenses")', $this->src);
        $this->assertStringContainsString("AS pending_ct", $this->src);
        $this->assertStringContainsString("AS approved_sum", $this->src);
        // The KPI totals reuse those rows instead of re-querying.
        $this->assertStringContainsString("\$total_death_expenses   = (float) \$de['approved_sum'];", $this->src);
        $this->assertStringContainsString("\$total_general_expenses = (float) \$ge['approved_sum'];", $this->src);
    }

    public function testSeparateApprovedSumQueriesAreGone(): void
    {
        // Match the standalone form (the closing `")`) so this does not trip on the
        // trend query, which legitimately sums the same tables per month.
        $this->assertStringNotContainsString("FROM death_expenses WHERE status IN ('approved','paid')\")", $this->src);
        $this->assertStringNotContainsString("FROM general_expenses WHERE status IN ('approved','paid')\")", $this->src);
        $this->assertStringNotContainsString("SELECT COUNT(*) FROM death_expenses WHERE status = 'pending'", $this->src);
    }
}
