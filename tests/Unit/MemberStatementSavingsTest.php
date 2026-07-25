<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Member Financial Statement used to (a) skim the whole entrance fee off a
 * member's total, so a member who brought in M-Koba savings showed 0 monthly paid
 * and every month red, and (b) always render 12 months — billing future months
 * that haven't happened. It now mirrors the group ledger: carried-in M-Koba money
 * is an opening balance (never skimmed as entrance, always counts toward the
 * schedule), the schedule runs from the later of the group start and the member's
 * join month, and only up to the current month (plus any months paid in advance).
 */
class MemberStatementSavingsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_statement.php');
    }

    public function testOpeningIsSplitFromNewMoneyViaMkobaMarkers(): void
    {
        $this->assertStringContainsString('AS opening', $this->src);
        $this->assertStringContainsString('AS newmoney', $this->src);
        $this->assertStringContainsString('mkoba_trans_id IS NOT NULL', $this->src);
        $this->assertStringContainsString("account = 'M-Koba'", $this->src);
    }

    public function testEntranceIsPaidFromNewMoneyOnly(): void
    {
        $this->assertStringContainsString('$entrance_paid_amt = min($new_money, $entrance_amt)', $this->src);
        // The old skim of the whole pot is gone.
        $this->assertStringNotContainsString('max(0, $total_paid - $entrance_amt)', $this->src);
    }

    public function testOpeningCountsTowardTheSchedule(): void
    {
        $this->assertStringContainsString('$monthly_pot = $opening + max(0, $new_money - $entrance_paid_amt)', $this->src);
        $this->assertStringContainsString('$remaining_pot = $monthly_pot', $this->src);
    }

    public function testScheduleAnchorsAtLaterOfStartAndJoinMonth(): void
    {
        $this->assertStringContainsString("strtotime(\$member['created_at']) > \$anchor_ts", $this->src);
        $this->assertStringContainsString('$anchor_ym = date(\'Y-m-01\', $anchor_ts)', $this->src);
    }

    public function testNoLongerBillsAFullTwelveMonths(): void
    {
        // The hard 12-month floor (which billed future months) is gone; the count
        // is elapsed months (+ advance).
        $this->assertStringNotContainsString('max(12, $total_months_covered', $this->src);
        $this->assertStringContainsString('$columns_count = max(1, $elapsed + 1, $total_months_covered)', $this->src);
    }

    public function testOpeningTileIsShown(): void
    {
        $this->assertStringContainsString('M-Koba Savings', $this->src);
        $this->assertStringContainsString('number_format($opening, 0)', $this->src);
    }
}
