<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Member Financial Statement's per-member schedule — opening split, entrance from
 * new money only, elapsed-month counting, no 12-month floor — used to live inline here.
 * It now comes from the shared module (cs_member_schedule / cs_build_schedule), so this
 * asserts the statement DELEGATES and still renders the pieces the page owns. The rules
 * themselves are covered by MemberScheduleTest.
 */
class MemberStatementSavingsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_statement.php');
    }

    public function testScheduleComesFromTheSharedModule(): void
    {
        $this->assertStringContainsString('$sched = cs_member_schedule($pdo, $member_id);', $this->src);
        // The mapped fields the display relies on.
        $this->assertStringContainsString("\$distribution         = \$sched['distribution'];", $this->src);
        $this->assertStringContainsString("\$entrance_status      = \$sched['entrance_status'];", $this->src);
        $this->assertStringContainsString("\$total_months_covered = \$sched['total_months_covered'];", $this->src);
    }

    public function testTheOldInlineScheduleMathIsGone(): void
    {
        // The duplicated SQL split, entrance skim, pot and anchor loop must be removed.
        $this->assertStringNotContainsString('AS newmoney', $this->src);
        $this->assertStringNotContainsString('$entrance_paid_amt = min($new_money, $entrance_amt)', $this->src);
        $this->assertStringNotContainsString('$monthly_pot = $opening', $this->src);
        $this->assertStringNotContainsString("strtotime(\$member['created_at']) > \$anchor_ts", $this->src);
        $this->assertStringNotContainsString('max(12, $total_months_covered', $this->src);
    }

    public function testAdvanceRowLabelStaysLocalisedInTheView(): void
    {
        // The advance/credit label is a display concern and stays in the page (i18n),
        // appended only when the module reports money beyond the shown months.
        $this->assertStringContainsString("if (\$sched['advance'] > 0)", $this->src);
        $this->assertStringContainsString("'ZIADA (ADVANCE)' : 'ADVANCE / CREDIT'", $this->src);
    }

    public function testOpeningTileIsShown(): void
    {
        // The statement still renders the M-Koba opening balance from $opening (mapped
        // from the module result).
        $this->assertStringContainsString('M-Koba Savings', $this->src);
        $this->assertStringContainsString('number_format($opening, 0)', $this->src);
    }
}
