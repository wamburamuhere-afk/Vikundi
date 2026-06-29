<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure deadline math behind the member auto-termination sweep
 * (audit M4). The DB-touching parts (the sweep + the once-per-day throttle) are
 * verified live; this covers the calculation that decides how much each member
 * must have paid by a given moment.
 */
class AutoTerminationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Loading the file only defines functions — the CLI/header dispatch at
        // the bottom is inert here (not invoked directly, and no $pdo in scope).
        require_once __DIR__ . '/../../actions/auto_terminate_members.php';
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'deadline_day'             => 15,
            'deadline_time'            => '23:59',
            'monthly_contribution'     => 10000,
            'entrance_fee'             => 5000,
            'contribution_start_date'  => '2026-01-01',
            'contribution_grace_days'  => 0,
        ], $overrides);
    }

    public function testNothingDueBeforeFirstDeadline(): void
    {
        // Jan 10, deadline is the 15th -> no deadline completed yet.
        $total = vk_required_contribution_total($this->settings(), new DateTime('2026-01-10 09:00'));
        $this->assertSame(0.0, $total);
    }

    public function testFirstMonthDueAfterDeadline(): void
    {
        // Jan 20, past the 15th -> entrance + 1 month.
        $total = vk_required_contribution_total($this->settings(), new DateTime('2026-01-20 09:00'));
        $this->assertSame(15000.0, $total); // 5000 + 1 * 10000
    }

    public function testPreviousMonthsOnlyBeforeThisMonthsDeadline(): void
    {
        // Mar 10: Jan & Feb deadlines passed, March's (15th) not yet -> 2 months.
        $total = vk_required_contribution_total($this->settings(), new DateTime('2026-03-10 09:00'));
        $this->assertSame(25000.0, $total); // 5000 + 2 * 10000
    }

    public function testGraceDaysExtendTheDeadline(): void
    {
        // Jan 18 with 5 grace days -> effective deadline is the 20th, nothing due.
        $total = vk_required_contribution_total(
            $this->settings(['contribution_grace_days' => 5]),
            new DateTime('2026-01-18 09:00')
        );
        $this->assertSame(0.0, $total);
    }

    public function testDeadlineTimeBoundaryOnTheDay(): void
    {
        // On the deadline day, after the deadline time -> this month counts.
        $total = vk_required_contribution_total(
            $this->settings(['deadline_time' => '23:00']),
            new DateTime('2026-01-15 23:30')
        );
        $this->assertSame(15000.0, $total);
    }

    public function testThrottleAndCliEntryPointsArePresent(): void
    {
        // Recurrence guard for M4: the file must keep the daily throttle marker
        // and the direct-CLI entry point, so it never reverts to running the
        // heavy sweep on every page load.
        $src = file_get_contents(__DIR__ . '/../../actions/auto_terminate_members.php');
        $this->assertStringContainsString('auto_termination_last_run', $src);
        $this->assertStringContainsString('vk_auto_termination_due', $src);
        $this->assertStringContainsString('realpath($argv[0]) === __FILE__', $src);
    }

    public function testSweepCountsApprovedContributionsAsPaid(): void
    {
        // Regression: the paid-total in the sweep must count 'approved'
        // contributions (the live workflow ends at 'approved', not 'confirmed').
        // Counting only 'confirmed' zeroed every real payment and swept fully
        // paid-up members into dormant. Keep the status set aligned with the
        // rest of the app: confirmed / approved / '' (legacy blank).
        $src = file_get_contents(__DIR__ . '/../../actions/auto_terminate_members.php');

        // The paid-total CASE must include 'approved', and must not have
        // regressed to an equals-'confirmed'-only test.
        $this->assertMatchesRegularExpression(
            "/con\.status\s+IN\s*\(\s*'confirmed'\s*,\s*'approved'\s*,\s*''\s*\)/",
            $src,
            "Auto-termination must count approved contributions as paid."
        );
        $this->assertStringNotContainsString("con.status = 'confirmed'", $src);
    }
}
