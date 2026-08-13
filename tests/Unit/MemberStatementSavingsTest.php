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
        // The page must never re-derive the schedule. It now passes an "as of" so the
        // group can reprint an earlier month without the figures moving to today.
        $this->assertMatchesRegularExpression(
            '/\$sched\s*=\s*cs_member_schedule\(\$pdo,\s*\$member_id,\s*\$as_of\)/',
            $this->src
        );
        // The calendar and the Target/Actual block are derived from that same schedule,
        // not recomputed — one source of truth all the way to the page.
        $this->assertStringContainsString('cs_calendar_grid($sched, $as_of)', $this->src);
        $this->assertStringContainsString('cs_year_summary($grid)', $this->src);
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
        // Money the schedule could not lay on any month must still reach the reader.
        // It used to be a fake 13th column labelled "ADVANCE / CREDIT", which broke the
        // twelve-month grid; it is now carried through cs_calendar_grid() as
        // `unallocated` and printed as a bilingual note under the summary total.
        $layout = file_get_contents(__DIR__ . '/../../includes/statement_layout.php');
        $this->assertStringContainsString("\$t['unallocated'] > 0", $layout);
        $this->assertStringContainsString("number_format(\$t['paid'], 0)", $layout);
        $this->assertStringContainsString('Total contributed:', $layout);
        $this->assertStringContainsString('Jumla aliyotoa:', $layout);
    }

    public function testOpeningTileIsShown(): void
    {
        // The M-Koba opening balance must stay visible — it once read 0 for every
        // imported member, which is the bug the standing module was built to end.
        // It now sits in the Contribution Details panel rather than a KPI tile.
        $this->assertStringContainsString('Opening (M-Koba)', $this->src);
        $this->assertStringContainsString('Akiba ya M-Koba', $this->src);
        $this->assertStringContainsString('$money($opening)', $this->src);
    }
}
