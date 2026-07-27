<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The last three screens that re-derived contribution standing — the member profile
 * schedule, the contributions grid, and its AJAX feed — each carried a `?? 10000` /
 * `?? 20000` default that fabricated a target and a false entrance whenever the group
 * had no monthly/entrance set (the current M-Koba case). They now follow the shared
 * rule in includes/contribution_standing.php: unset => 0 (no target). The profile
 * additionally adopts the module's opening-split and elapsed-month counting, dropping
 * its old "bill a full 12 months" floor.
 */
class RemainingConsumersUseStandingModuleTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── profile schedule ─────────────────────────────────────────────────────
    public function testProfileWiresToTheModule(): void
    {
        $s = $this->src('app/constant/profile/profile.php');
        $this->assertStringContainsString("require_once __DIR__ . '/../../../includes/contribution_standing.php'", $s);
        $this->assertStringNotContainsString("'monthly_contribution'] ?? 10000", $s);
        $this->assertStringNotContainsString("'entrance_fee'] ?? 20000", $s);
        // The whole per-member schedule now comes from the shared module.
        $this->assertStringContainsString('$sched = cs_member_schedule($pdo, (int) $member_id);', $s);
    }

    public function testProfileHasNoInlineScheduleMathLeft(): void
    {
        $s = $this->src('app/constant/profile/profile.php');
        // No more billing a hard 12 months of future dues, and no duplicated split /
        // month-counting — all of that lives in cs_build_schedule now.
        $this->assertStringNotContainsString('max(12,', $s);
        $this->assertStringNotContainsString('cs_months_elapsed($anchor_ym)', $s);
        $this->assertStringNotContainsString('AS opening', $s);
        $this->assertStringNotContainsString('floor($monthly_pot / $monthly_amt)', $s);
    }

    // ── contributions grid + its AJAX feed ───────────────────────────────────
    public function testManageContributionsHasNoFabricatedDefault(): void
    {
        $s = $this->src('app/bms/customer/manage_contributions.php');
        $this->assertStringNotContainsString("'monthly_contribution'] ?? 10000", $s);
        $this->assertStringNotContainsString("'entrance_fee'] ?? 20000", $s);
        $this->assertStringContainsString("floatval(\$settings_raw['monthly_contribution'] ?? 0)", $s);
        $this->assertStringContainsString("floatval(\$settings_raw['entrance_fee'] ?? 0)", $s);
    }

    public function testContributionLedgerApiHasNoFabricatedDefault(): void
    {
        $s = $this->src('api/get_contribution_ledger.php');
        $this->assertStringNotContainsString("'monthly_contribution'] ?? 10000", $s);
        $this->assertStringNotContainsString("'entrance_fee'] ?? 20000", $s);
        $this->assertStringContainsString("floatval(\$settings_raw['monthly_contribution'] ?? 0)", $s);
        $this->assertStringContainsString("floatval(\$settings_raw['entrance_fee'] ?? 0)", $s);
    }
}
