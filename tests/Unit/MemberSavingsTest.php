<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Member Home + savings streak. Real tests for the pure streak / month logic, and
 * source-guards pinning the shared savings figures, the landing routing and the
 * merge-field consistency fix.
 */
class MemberSavingsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/member_savings.php';
    }

    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── Pure: previous month ─────────────────────────────────────────────────

    public function testPrevMonthRollsOverTheYear(): void
    {
        $this->assertSame('2026-06', vk_prev_month('2026-07'));
        $this->assertSame('2025-12', vk_prev_month('2026-01'));
    }

    // ── Pure: streak ─────────────────────────────────────────────────────────

    public function testStreakCountsConsecutiveMonths(): void
    {
        $months = ['2026-04', '2026-05', '2026-06'];
        $this->assertSame(3, vk_contribution_streak($months, '2026-06')); // paid this month too
    }

    public function testInProgressCurrentMonthDoesNotBreakStreak(): void
    {
        // Paid Jan–Jun, hasn't paid July yet — streak is still 6, not 0.
        $months = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'];
        $this->assertSame(6, vk_contribution_streak($months, '2026-07'));
    }

    public function testGapResetsTheStreak(): void
    {
        // Missed April: streak from June back is just May, June... wait June->May then April missing.
        $months = ['2026-02', '2026-03', '2026-05', '2026-06'];
        $this->assertSame(2, vk_contribution_streak($months, '2026-06')); // Jun, May, then April gap
    }

    public function testEmptyHistoryIsZeroStreak(): void
    {
        $this->assertSame(0, vk_contribution_streak([], '2026-07'));
    }

    public function testStreakSpanningYearBoundary(): void
    {
        $months = ['2025-11', '2025-12', '2026-01'];
        $this->assertSame(3, vk_contribution_streak($months, '2026-01'));
    }

    // ── Savings figures are keyed on customer_id, matching the statement ──────

    public function testSavingsHelperMatchesTheStatementDefinition(): void
    {
        $p = $this->src('includes/member_savings.php');
        // resolves login user -> customer (contributions/fines key on customer_id)
        $this->assertStringContainsString('SELECT customer_id FROM customers WHERE user_id', $p);
        // same status/type filter as app/constant/reports/member_statement.php
        $this->assertStringContainsString("status IN ('confirmed', 'approved', '')", $p);
        $this->assertStringContainsString("contribution_type IN ('monthly', 'entrance', 'other', '')", $p);
        // fines are the pending ones, keyed on customer_id
        $this->assertStringContainsString("FROM fines WHERE customer_id = ? AND status = 'pending'", $p);
    }

    public function testMergeFieldNowUsesTheSharedTotalNotUserIdSum(): void
    {
        // The document letter's {member_contributions} must show the same figure
        // as the statement/home — resolved via customer_id, not a raw user_id sum.
        $p = $this->src('includes/document_merge_fields.php');
        $this->assertStringContainsString('vk_member_customer_id($pdo', $p);
        $this->assertStringContainsString('vk_member_savings_total($pdo', $p);
        $this->assertStringNotContainsString("WHERE member_id = ? AND status = 'approved'", $p);
    }

    // ── Wiring ───────────────────────────────────────────────────────────────

    public function testMemberHomeIsSelfScopedAndUsesTheHelper(): void
    {
        $p = $this->src('app/bms/customer/my_home.php');
        $this->assertStringContainsString('require_login.php', $p);          // authenticated
        $this->assertStringContainsString('vk_member_customer_id($pdo', $p);  // own record only
        $this->assertStringContainsString('vk_member_savings_total', $p);
        $this->assertStringContainsString('vk_contribution_streak', $p);
    }

    public function testMembersLandOnHomeLeadershipOnDashboard(): void
    {
        $p = $this->src('core/permissions.php');
        $this->assertStringContainsString("return 'my_home'", $p);
        $this->assertStringContainsString("canCreate('manage_contributions')", $p);
        // the dead loan-dashboard landing is gone
        $this->assertStringNotContainsString("return 'loan-dashboard'", $p);
        $this->assertStringContainsString("'my_home'", $this->src('roots.php'));
    }
}
