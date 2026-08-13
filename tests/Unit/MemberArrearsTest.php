<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * The arrears figure the member's dashboard shows: how much they owe, over how
 * many months.
 *
 * It is derived from the SAME grid the statement prints. A dashboard announcing
 * "you owe 60,000" above a statement showing a different shortfall is how a member
 * stops trusting both documents, so there is exactly one calculation and these
 * tests pin it to the statement rather than to a second opinion.
 */
class MemberArrearsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_standing.php';
    }

    private function arrears(
        float $paid,
        float $monthly,
        string $start,
        ?string $join,
        string $asOf
    ): array {
        $sched = cs_build_schedule(0, $paid, $monthly, 0, $start, $join, new DateTime($asOf));
        return cs_arrears_from_grid(cs_calendar_grid($sched, new DateTime($asOf)));
    }

    // -------------------------------------------------------------------------
    // What a member is actually told
    // -------------------------------------------------------------------------

    public function testAMemberWhoHasPaidNothingOwesEveryElapsedMonth(): void
    {
        $a = $this->arrears(0, 20000, '2026-01-01', null, '2026-04-15');

        $this->assertTrue($a['behind']);
        $this->assertSame(80000.0, $a['amount']);
        $this->assertSame(4, $a['months']);
        $this->assertSame('2026-01', $a['oldest']);
    }

    public function testAPartialMonthCountsAsOneMonthBehindForItsShortfall(): void
    {
        // Three months due at 20,000 = 60,000. Paid 50,000: Jan and Feb full,
        // March 10,000 short. One month behind, 10,000 owed — not three months.
        $a = $this->arrears(50000, 20000, '2026-01-01', null, '2026-03-15');

        $this->assertTrue($a['behind']);
        $this->assertSame(10000.0, $a['amount']);
        $this->assertSame(1, $a['months']);
        $this->assertSame('2026-03', $a['oldest']);
    }

    public function testAMemberWhoIsUpToDateIsNotBehind(): void
    {
        $a = $this->arrears(60000, 20000, '2026-01-01', null, '2026-03-15');

        $this->assertFalse($a['behind']);
        $this->assertSame(0.0, $a['amount']);
        $this->assertSame(0, $a['months']);
        $this->assertNull($a['oldest']);
    }

    public function testAMemberWhoPaidAheadIsNotBehind(): void
    {
        // Paid a full year in March. Nothing is owed, and the surplus must not be
        // reported as a negative debt.
        $a = $this->arrears(240000, 20000, '2026-01-01', null, '2026-03-15');

        $this->assertFalse($a['behind']);
        $this->assertSame(0.0, $a['amount']);
    }

    // -------------------------------------------------------------------------
    // What can never be counted as a debt
    // -------------------------------------------------------------------------

    public function testMonthsBeforeJoiningAreNeverOwed(): void
    {
        // Joined in May, paid nothing, viewed in August. Four months owed (May-Aug),
        // never eight — the member cannot be billed for a group they had not joined.
        $a = $this->arrears(0, 20000, '2026-01-01', '2026-05-10', '2026-08-15');

        $this->assertSame(4, $a['months']);
        $this->assertSame(80000.0, $a['amount']);
        $this->assertSame('2026-05', $a['oldest']);
    }

    public function testFutureMonthsAreNeverOwed(): void
    {
        // Two months elapsed, ten to come. Only the two can be owed.
        $a = $this->arrears(0, 20000, '2026-01-01', null, '2026-02-15');

        $this->assertSame(2, $a['months']);
        $this->assertSame(40000.0, $a['amount']);
    }

    public function testAFutureMonthCarryingATargetIsStillNotADebt(): void
    {
        // The test above passes whether or not cs_arrears_from_grid() checks `due`,
        // because cs_calendar_grid() already zeroes the target on future months — so
        // it proves the grid's behaviour, not this function's. This one feeds a grid
        // that violates that invariant, which is the only way to prove the guard here
        // does anything. It matters because grids are now built in two places, and
        // the cost of the guard failing is a member being told they owe money for a
        // month that has not happened.
        $grid = ['years' => [2026 => [
            1 => ['target' => 20000.0, 'allocated' => 0.0, 'status' => 'unpaid', 'due' => true],
            2 => ['target' => 20000.0, 'allocated' => 0.0, 'status' => 'future', 'due' => false],
            3 => ['target' => 20000.0, 'allocated' => 0.0, 'status' => 'future', 'due' => false],
        ]]];

        $a = cs_arrears_from_grid($grid);
        $this->assertSame(1, $a['months'], 'only the elapsed month is a debt');
        $this->assertSame(20000.0, $a['amount']);
    }

    public function testNoMonthlyRuleMeansNobodyIsEverBehind(): void
    {
        // Save-what-you-can. With no target there is no debt, so the banner must
        // never appear — including for a member who has paid nothing at all.
        $this->assertFalse($this->arrears(0, 0, '2026-01-01', null, '2026-06-15')['behind']);
        $this->assertFalse($this->arrears(500000, 0, '2026-01-01', null, '2026-06-15')['behind']);
    }

    // -------------------------------------------------------------------------
    // Agreement with the statement
    // -------------------------------------------------------------------------

    public function testArrearsMatchTheDeficitTheStatementPrints(): void
    {
        // The dashboard figure and the statement's variance must be the same number.
        $asOf  = new DateTime('2026-04-15');
        $sched = cs_build_schedule(0, 50000, 20000, 0, '2026-01-01', null, $asOf);
        $grid  = cs_calendar_grid($sched, $asOf);

        $arrears  = cs_arrears_from_grid($grid);
        $variance = cs_year_summary($grid)['total']['variance'];

        $this->assertSame(-$arrears['amount'], $variance);
        $this->assertSame(30000.0, $arrears['amount']);
    }

    // -------------------------------------------------------------------------
    // The dashboard wiring
    // -------------------------------------------------------------------------

    public function testDashboardReadsTheSharedModuleRatherThanItsOwnQuery(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/dashboard.php');

        $this->assertStringContainsString("require_once ROOT_DIR . '/includes/contribution_standing.php';", $src);
        $this->assertStringContainsString('cs_member_arrears($pdo, $my_member_id)', $src);
    }

    public function testTheBannerIsShownOnlyToSomeoneWhoIsBehind(): void
    {
        // A standing "you owe nothing" banner trains people to stop reading banners.
        $src = file_get_contents(__DIR__ . '/../../app/dashboard.php');
        $this->assertStringContainsString("<?php if (\$my_arrears['behind']): ?>", $src);
    }

    public function testTheBannerSpeaksBothLanguagesAndCountsCorrectly(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/dashboard.php');

        $this->assertStringContainsString('Unadaiwa', $src);
        $this->assertStringContainsString('You owe', $src);
        // "1 miezi" would be wrong in Swahili and "1 months" wrong in English.
        $this->assertStringContainsString("'mwezi' : 'miezi'", $src);
        $this->assertStringContainsString("'month' : 'months'", $src);
    }

    public function testAMemberWithNoCustomerRecordIsNotToldTheyOweAnything(): void
    {
        // Admin accounts have no customers row. The banner must stay silent rather
        // than defaulting to a zero-member lookup.
        $src = file_get_contents(__DIR__ . '/../../app/dashboard.php');
        $this->assertStringContainsString('if ($my_member_id) {', $src);
        $this->assertStringContainsString("\$my_arrears = ['behind' => false", $src);
    }
}
