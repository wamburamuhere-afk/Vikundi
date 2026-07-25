<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Group Reports page (vicoba_reports) once computed Member Savings with its
 * own query gated on status='confirmed', so it read TSh 0 savings and a negative
 * cash balance while the M-Koba imports (stored 'approved') were ignored. It now
 * derives savings from the shared contribution-standing module, so it can never
 * drift from the ledger/dashboard again — and the group total is every member's
 * money (not just the currently-active ones).
 */
class VicobaReportsSavingsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/vicoba_reports.php');
    }

    public function testSavingsComeFromTheSharedStandingModule(): void
    {
        $this->assertStringContainsString('contribution_standing.php', $this->src);
        $this->assertStringContainsString('cs_group_standing($pdo)', $this->src);
        // The savings figure is each member's standing total.
        $this->assertStringContainsString("'total_savings' => \$st['total']", $this->src);
    }

    public function testPageNoLongerRunsItsOwnConfirmedOnlySavingsQuery(): void
    {
        $this->assertStringNotContainsString("co.status='confirmed'", $this->src);
        $this->assertStringNotContainsString("co.status IN ('confirmed','approved','') THEN co.amount ELSE 0 END),0) AS entrance", $this->src);
    }

    public function testGroupTotalIsAllMembersAndActiveIsASeparateCount(): void
    {
        // Total savings sums every member in the table; the active headcount is
        // a distinct query, so a dormancy blip can't zero the group total.
        $this->assertStringContainsString('$members_total   = count($savings_data)', $this->src);
        $this->assertStringContainsString("FROM customers WHERE status <> 'deleted'", $this->src);
        $this->assertStringContainsString("SELECT COUNT(*) FROM customers WHERE status = 'active'", $this->src);
    }
}
