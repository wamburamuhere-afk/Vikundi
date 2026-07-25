<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * The single source of truth for member contribution standing
 * (includes/contribution_standing.php). Covers the pure math the reports will
 * call; the one DB function (cs_group_standing) is verified live.
 */
class ContributionStandingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_standing.php';
    }

    // ── cs_is_opening ────────────────────────────────────────────────────────
    public function testOpeningIsDetectedByMkobaMarkers(): void
    {
        $this->assertTrue(cs_is_opening('3820502806778077_0783459353', 'M-Koba'));
        $this->assertTrue(cs_is_opening('DBS2N6S4DVM', null));      // has a trans id
        $this->assertTrue(cs_is_opening(null, 'M-Koba'));           // M-Koba account
        $this->assertFalse(cs_is_opening(null, 'Cash'));            // new money
        $this->assertFalse(cs_is_opening('', 'Bank'));
        $this->assertFalse(cs_is_opening('   ', null));             // blank trans id
    }

    // ── cs_months_elapsed ────────────────────────────────────────────────────
    public function testMonthsElapsedIsInclusiveOfTheAnchorMonth(): void
    {
        // First contribution Feb, as of July -> Feb,Mar,Apr,May,Jun,Jul = 6.
        $this->assertSame(6, cs_months_elapsed('2026-02-15', new DateTime('2026-07-25')));
        // Same month as the anchor counts as 1.
        $this->assertSame(1, cs_months_elapsed('2026-07-01', new DateTime('2026-07-25')));
    }

    public function testMonthsElapsedHandlesMissingOrFutureAnchor(): void
    {
        $this->assertSame(0, cs_months_elapsed(null, new DateTime('2026-07-25')));
        $this->assertSame(0, cs_months_elapsed('', new DateTime('2026-07-25')));
        // Anchor in the future -> nothing elapsed.
        $this->assertSame(0, cs_months_elapsed('2026-09-01', new DateTime('2026-07-25')));
    }

    // ── cs_expected_to_date ──────────────────────────────────────────────────
    public function testNoFixedMonthlyMeansNoExpectation(): void
    {
        // The save-what-you-can case: monthly 0 -> nothing is ever "owed".
        $this->assertSame(0.0, cs_expected_to_date(0, '2026-02-01', new DateTime('2026-07-25')));
    }

    public function testExpectedIsMonthlyTimesElapsedMonths(): void
    {
        // 20,000/month, first contribution Feb, as of July -> 6 * 20,000.
        $this->assertSame(120000.0, cs_expected_to_date(20000, '2026-02-01', new DateTime('2026-07-25')));
        // No anchor (no contributions yet) -> nothing expected.
        $this->assertSame(0.0, cs_expected_to_date(20000, null, new DateTime('2026-07-25')));
    }

    // ── cs_standing ──────────────────────────────────────────────────────────
    public function testOpeningCountsSoItNeverCreatesADeficit(): void
    {
        // 10k carried in, nothing new, 20k expected -> a real 10k shortfall.
        $s = cs_standing(10000, 0, 20000);
        $this->assertSame(10000.0, $s['total']);
        $this->assertSame(-10000.0, $s['surplus_deficit']);
        $this->assertSame('behind', $s['status']);

        // 55k carried in vs 20k expected -> ahead.
        $s2 = cs_standing(55000, 0, 20000);
        $this->assertSame(35000.0, $s2['surplus_deficit']);
        $this->assertSame('ahead', $s2['status']);
    }

    public function testNoTargetMeansOntrackAndSurplusEqualsSavings(): void
    {
        $s = cs_standing(10000, 5000, 0);
        $this->assertSame(15000.0, $s['total']);
        $this->assertSame(15000.0, $s['surplus_deficit']);
        $this->assertSame('ontrack', $s['status']);
    }

    public function testBalanceSubtractsAssistanceReceived(): void
    {
        $s = cs_standing(50000, 0, 0, 12000);
        $this->assertSame(50000.0, $s['total']);
        $this->assertSame(38000.0, $s['balance']); // 50k − 12k aid
    }

    public function testGroupQueryCountsApprovedContributions(): void
    {
        // The one place the status set lives now: the module's DB read must count
        // confirmed / approved / '' (the live workflow ends at 'approved').
        $src = file_get_contents(__DIR__ . '/../../includes/contribution_standing.php');
        $this->assertStringContainsString("co.status IN ('confirmed','approved','')", $src);
        $this->assertStringNotContainsString("co.status = 'confirmed'", $src);
    }

    public function testGroupSavingsTotalIsMemberScoped(): void
    {
        // cs_group_savings_total must use the SAME member-scoped join as
        // cs_group_standing, so the dashboard "Contributions" KPI and the Group
        // Reports savings total are exactly equal (verified numerically on live).
        $src = file_get_contents(__DIR__ . '/../../includes/contribution_standing.php');
        $this->assertStringContainsString('function cs_group_savings_total', $src);
        $this->assertStringContainsString("JOIN customers c ON c.customer_id = co.member_id AND c.status <> 'deleted'", $src);
        $this->assertStringContainsString("co.contribution_type <> 'fine'", $src);
    }
}
