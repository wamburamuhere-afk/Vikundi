<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * cs_calendar_grid() and cs_year_summary() — the NSSF-style year x month calendar
 * and the Target/Actual block printed beneath it.
 *
 * The rule these encode, agreed with the group: money is pooled and laid forward
 * from the member's own anchor month, arrears first. A member who pays 100,000
 * against a 20,000 monthly target has covered five months, whichever day the money
 * arrived. The Transactions statement shows the single 100,000 event; the
 * Contributions statement shows the five months. Same money, two views.
 *
 * Pure math, no DB — cs_member_schedule() is the only piece that touches one.
 */
class ContributionCalendarTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_standing.php';
    }

    /** cs_build_schedule() with the arguments spelled out, to keep the cases readable. */
    private function schedule(
        float $newMoney,
        float $monthly,
        string $start,
        ?string $join,
        string $asOf,
        float $opening = 0.0
    ): array {
        return cs_build_schedule($opening, $newMoney, $monthly, 0, $start, $join, new DateTime($asOf));
    }

    // -------------------------------------------------------------------------
    // The rule as the group stated it
    // -------------------------------------------------------------------------

    public function testOneHundredThousandInJanuaryCoversFiveMonthsAtTwentyThousand(): void
    {
        // The example given verbatim: 100k paid, 20k monthly target, fills Jan..May.
        $grid = cs_calendar_grid(
            $this->schedule(100000, 20000, '2026-01-01', null, '2026-05-15'),
            new DateTime('2026-05-15')
        );

        foreach ([1, 2, 3, 4, 5] as $m) {
            $this->assertSame('paid', $grid['years'][2026][$m]['status'], "month $m should be covered");
            $this->assertSame(20000.0, $grid['years'][2026][$m]['allocated']);
        }
        // June onward is not owed yet and must not read as a debt.
        $this->assertSame('future', $grid['years'][2026][6]['status']);
        $this->assertSame(0.0, $grid['years'][2026][6]['target']);
    }

    public function testShortPaymentLeavesTheDeficitOnThatMonth(): void
    {
        // The second example: 10k paid against a 20k target — that month holds the 10k
        // and the member is 10k short. Not carried, not rounded away.
        $grid = cs_calendar_grid(
            $this->schedule(10000, 20000, '2026-01-01', null, '2026-01-15'),
            new DateTime('2026-01-15')
        );

        $jan = $grid['years'][2026][1];
        $this->assertSame('partial', $jan['status']);
        $this->assertSame(10000.0, $jan['allocated']);
        $this->assertSame(20000.0, $jan['target']);
        $this->assertSame(-10000.0, cs_year_summary($grid)['years'][2026]['variance']);
    }

    // -------------------------------------------------------------------------
    // Boundaries that break naive implementations
    // -------------------------------------------------------------------------

    public function testAllocationSpillsAcrossTheYearBoundary(): void
    {
        // 100k in November at 20k/month reaches March of the FOLLOWING year. A grid
        // built per-calendar-year without carry would lose three of those months.
        $grid = cs_calendar_grid(
            $this->schedule(100000, 20000, '2025-11-01', null, '2026-03-15'),
            new DateTime('2026-03-15')
        );

        $this->assertSame(2025, $grid['first_year']);
        $this->assertSame(2026, $grid['last_year']);
        $this->assertSame('paid', $grid['years'][2025][11]['status']);
        $this->assertSame('paid', $grid['years'][2025][12]['status']);
        foreach ([1, 2, 3] as $m) {
            $this->assertSame('paid', $grid['years'][2026][$m]['status'], "Jan-Mar spill, month $m");
        }
        $this->assertSame('future', $grid['years'][2026][4]['status']);
    }

    public function testMonthsBeforeTheMemberJoinedAreNotDebts(): void
    {
        // Joined in May. January to April must read "not yet a member", never "unpaid" —
        // a red cell there accuses someone of missing a payment they never owed.
        $grid = cs_calendar_grid(
            $this->schedule(60000, 20000, '2026-01-01', '2026-05-10', '2026-08-15'),
            new DateTime('2026-08-15')
        );

        foreach ([1, 2, 3, 4] as $m) {
            $this->assertSame('before_join', $grid['years'][2026][$m]['status'], "month $m predates the join");
            $this->assertSame(0.0, $grid['years'][2026][$m]['target'], 'a pre-join month can never be billed');
        }
        $this->assertSame('paid', $grid['years'][2026][5]['status']);
        $this->assertSame('unpaid', $grid['years'][2026][8]['status']);

        // Four elapsed months since joining (May..Aug), 60k paid against 80k due.
        $summary = cs_year_summary($grid);
        $this->assertSame(80000.0, $summary['years'][2026]['target']);
        $this->assertSame(60000.0, $summary['years'][2026]['actual']);
        $this->assertSame(-20000.0, $summary['years'][2026]['variance']);
    }

    public function testPayingAheadReadsAsSurplusNotOverpayment(): void
    {
        // A full year paid in March. Only Jan-Mar are owed, so the target stops there
        // and the remaining nine months show as advance — a positive variance.
        $grid = cs_calendar_grid(
            $this->schedule(240000, 20000, '2026-01-01', null, '2026-03-15'),
            new DateTime('2026-03-15')
        );

        $this->assertSame('paid', $grid['years'][2026][3]['status']);
        $this->assertSame('advance', $grid['years'][2026][4]['status']);
        $this->assertSame(20000.0, $grid['years'][2026][4]['allocated']);
        $this->assertSame(0.0, $grid['years'][2026][4]['target'], 'a future month is not owed');

        $summary = cs_year_summary($grid);
        $this->assertSame(60000.0, $summary['years'][2026]['target']);
        $this->assertSame(240000.0, $summary['years'][2026]['actual']);
        $this->assertSame(180000.0, $summary['years'][2026]['variance']);
    }

    public function testMemberWhoHasPaidNothingOwesEveryElapsedMonth(): void
    {
        $grid = cs_calendar_grid(
            $this->schedule(0, 20000, '2026-01-01', null, '2026-04-15'),
            new DateTime('2026-04-15')
        );

        foreach ([1, 2, 3, 4] as $m) {
            $this->assertSame('unpaid', $grid['years'][2026][$m]['status']);
        }
        $this->assertSame('future', $grid['years'][2026][5]['status']);
        $this->assertSame(-80000.0, cs_year_summary($grid)['total']['variance']);
    }

    // -------------------------------------------------------------------------
    // The two figures on the page must reconcile
    // -------------------------------------------------------------------------

    public function testARemainderSmallerThanOneMonthStillLandsOnTheGrid(): void
    {
        // Production member #1: 285,000 against a 20,000 target = 14 whole months and
        // 5,000 left over. cs_build_schedule() covers whole months only, so without
        // handling that remainder it falls outside every column.
        $grid = cs_calendar_grid(
            $this->schedule(285000, 20000, '2026-07-01', null, '2026-08-13'),
            new DateTime('2026-08-13')
        );

        $total = 0.0;
        foreach ($grid['years'] as $cells) {
            foreach ($cells as $cell) {
                $total += $cell['allocated'];
            }
        }
        $this->assertSame(285000.0, $total, 'every shilling paid must appear somewhere on the grid');
        $this->assertSame(0.0, $grid['unallocated'], 'with a monthly rule nothing may be left unallocated');
    }

    public function testPanelSurplusAndSummaryVarianceCannotDisagree(): void
    {
        // The statement prints Surplus/Deficit in the details panel (from cs_standing)
        // and Variance in the summary (from the grid). They are derived by different
        // routes and appear on the same page, so a member reading 245,000 in one place
        // and 240,000 in the other is being shown a document that contradicts itself.
        $asOf  = new DateTime('2026-08-13');
        $sched = $this->schedule(285000, 20000, '2026-07-01', null, '2026-08-13');
        $grid  = cs_calendar_grid($sched, $asOf);

        $expected = cs_expected_to_date(20000, $sched['anchor_ym'], $asOf);
        $panel    = cs_standing(0, 285000, $expected);
        $summary  = cs_year_summary($grid);

        $this->assertSame(40000.0, $expected, 'two elapsed months at 20,000');
        $this->assertSame($panel['surplus_deficit'], $summary['total']['variance']);
        $this->assertSame(245000.0, $summary['total']['variance']);
    }

    // -------------------------------------------------------------------------
    // No fixed monthly rule (the group's current state)
    // -------------------------------------------------------------------------

    public function testNoMonthlyTargetProducesNoDeficitsAnywhere(): void
    {
        $grid = cs_calendar_grid(
            $this->schedule(500000, 0, '2026-01-01', null, '2026-06-15'),
            new DateTime('2026-06-15')
        );

        foreach ([1, 2, 3, 4, 5, 6] as $m) {
            $this->assertSame('no_target', $grid['years'][2026][$m]['status']);
            $this->assertSame(0.0, $grid['years'][2026][$m]['target']);
        }
        $this->assertSame(0.0, cs_year_summary($grid)['total']['variance']);
    }

    public function testTotalPaidSurvivesWhenThereIsNoMonthlyRule(): void
    {
        // The trap this guards: with no monthly rule the pot lands on no month, every
        // cell reads 0, and summing the cells would print "Total 0" for a member who
        // has paid 500,000. The grand total is what they brought in, always.
        $grid = cs_calendar_grid(
            $this->schedule(500000, 0, '2026-01-01', null, '2026-06-15'),
            new DateTime('2026-06-15')
        );

        $total = cs_year_summary($grid)['total'];
        $this->assertSame(0.0, $total['actual'], 'nothing can be laid on a month without a target');
        $this->assertSame(500000.0, $total['unallocated']);
        $this->assertSame(500000.0, $total['paid'], 'the member paid 500,000 and the statement must say so');
    }

    // -------------------------------------------------------------------------
    // Shape — the page prints twelve columns per row whatever the data says
    // -------------------------------------------------------------------------

    public function testEveryYearRowCarriesTwelveMonths(): void
    {
        $grid = cs_calendar_grid(
            $this->schedule(100000, 20000, '2025-11-01', null, '2026-03-15'),
            new DateTime('2026-03-15')
        );

        foreach ($grid['years'] as $year => $months) {
            $this->assertCount(12, $months, "year $year must print a full row");
            $this->assertSame(range(1, 12), array_keys($months));
        }
    }

    public function testCarriedInOpeningCountsTowardTheMonths(): void
    {
        // M-Koba money carried in is savings like any other and must fill months too,
        // never sit outside the calendar as if it had not been paid.
        $grid = cs_calendar_grid(
            $this->schedule(0, 20000, '2026-01-01', null, '2026-03-15', 40000),
            new DateTime('2026-03-15')
        );

        // 40,000 carried in covers exactly two months at a 20,000 target.
        $this->assertSame('paid', $grid['years'][2026][1]['status']);
        $this->assertSame('paid', $grid['years'][2026][2]['status']);
        $this->assertSame('unpaid', $grid['years'][2026][3]['status'], 'the pot runs out in March');
        $this->assertSame(40000.0, cs_year_summary($grid)['total']['paid']);
    }

    public function testGridReportsTheAsOfMonthItWasBuiltFor(): void
    {
        $grid = cs_calendar_grid(
            $this->schedule(100000, 20000, '2026-01-01', null, '2026-05-15'),
            new DateTime('2026-05-15')
        );

        $this->assertSame('2026-05-01', $grid['as_of_ym']);
        $this->assertSame('2026-01-01', $grid['anchor_ym']);
    }
}
