<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * cs_build_schedule() is the shared per-member monthly schedule that the member
 * statement and the profile page both render (they used to duplicate it). This
 * covers the pure math; the DB wrapper cs_member_schedule() just feeds it.
 */
class MemberScheduleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_standing.php';
    }

    public function testOpeningIsNeverSkimmedForTheEntranceFee(): void
    {
        // 50k carried in (opening) + 10k new, 20k entrance. Entrance comes off the NEW
        // money only, so the 50k opening stays whole and still counts toward the pot.
        $s = cs_build_schedule(50000, 10000, 0, 20000, '2026-01-01', null, new DateTime('2026-03-15'));
        $this->assertSame(50000.0, $s['opening']);
        $this->assertSame(60000.0, $s['total_paid']);
        $this->assertSame(10000.0, $s['entrance_paid']);      // capped at the 10k new money
        $this->assertSame('partial', $s['entrance_status']);
        $this->assertSame(50000.0, $s['monthly_pot']);        // opening + (new - entrance) = 50k + 0
    }

    public function testNoMonthlyMeansNoTargetAndThePotIsAllAdvance(): void
    {
        // monthly 0 -> every cell target 0, nothing "owed", the whole pot is advance.
        $s = cs_build_schedule(0, 30000, 0, 0, '2026-01-01', null, new DateTime('2026-04-15'));
        $this->assertSame(0, $s['total_months_covered']);
        $this->assertSame(30000.0, $s['advance']);
        $this->assertSame(4, $s['columns_count']);            // Jan..Apr inclusive
        foreach ($s['distribution'] as $cell) {
            $this->assertSame(0.0, $cell['target']);
        }
    }

    public function testMonthlyPotIsDistributedAcrossMonths(): void
    {
        // 25k pot, 10k/month -> two full months + 5k advance.
        $s = cs_build_schedule(0, 25000, 10000, 0, '2026-01-01', null, new DateTime('2026-01-15'));
        $this->assertSame(2, $s['total_months_covered']);
        $this->assertSame(10000.0, $s['distribution'][0]['amount']);
        $this->assertSame('paid', $s['distribution'][0]['status']);
        $this->assertSame(10000.0, $s['distribution'][1]['amount']);
        $this->assertSame(5000.0, $s['advance']);
    }

    public function testScheduleAnchorsAtTheLaterOfStartAndJoin(): void
    {
        // Group started Jan but the member joined March -> the schedule starts in March,
        // so the member is never billed for the months before they existed.
        $s = cs_build_schedule(0, 0, 10000, 0, '2026-01-01', '2026-03-01', new DateTime('2026-05-15'));
        $this->assertSame('2026-03-01', $s['anchor_ym']);
        $this->assertSame(3, $s['columns_count']);            // Mar, Apr, May
    }

    public function testEntranceStatusReflectsWhatWasPaid(): void
    {
        $this->assertSame('paid',   cs_build_schedule(0, 20000, 0, 20000, '2026-01-01', null, new DateTime('2026-01-15'))['entrance_status']);
        $this->assertSame('unpaid', cs_build_schedule(0, 0, 0, 20000, '2026-01-01', null, new DateTime('2026-01-15'))['entrance_status']);
    }
}
