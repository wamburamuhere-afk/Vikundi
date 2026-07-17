<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PR 6 of the audit-logs work: the per-user timeline groups a person's activity
 * into sign-in sessions. This exercises the pure grouping algorithm that powers
 * it (includes/activity_sessions.php).
 */
class ActivitySessionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/activity_sessions.php';
    }

    /** @param array<int,array{0:string,1:string}> $rows [action, 'H:i'] on a fixed day */
    private function events(array $rows): array
    {
        return array_map(
            fn($r) => ['action' => $r[0], 'created_at' => '2026-07-17 ' . $r[1] . ':00'],
            $rows
        );
    }

    public function test_empty_input_yields_no_sessions(): void
    {
        $this->assertSame([], vk_group_activity_sessions([]));
    }

    public function test_login_actions_logout_is_one_closed_session(): void
    {
        $s = vk_group_activity_sessions($this->events([
            ['Login', '09:00'], ['Created', '09:05'], ['Updated', '09:10'], ['Logout', '09:20'],
        ]));
        $this->assertCount(1, $s);
        $this->assertCount(4, $s[0]['events']);
        $this->assertSame('logout', $s[0]['ended']);
        $this->assertSame(strtotime('2026-07-17 09:00:00'), $s[0]['start_ts']);
        $this->assertSame(strtotime('2026-07-17 09:20:00'), $s[0]['last_ts']);
    }

    public function test_second_login_starts_a_new_session(): void
    {
        $s = vk_group_activity_sessions($this->events([
            ['Login', '09:00'], ['Created', '09:05'],
            ['Login', '11:00'], ['Deleted', '11:02'],
        ]));
        $this->assertCount(2, $s);
        $this->assertNull($s[0]['ended']);                 // first session had no logout
        $this->assertCount(2, $s[0]['events']);
        $this->assertCount(2, $s[1]['events']);
    }

    public function test_a_long_gap_splits_a_session(): void
    {
        // 40-minute gap (> 30m default) between two actions, no login/logout.
        $s = vk_group_activity_sessions($this->events([
            ['Created', '09:00'], ['Updated', '09:40'],
        ]));
        $this->assertCount(2, $s);
    }

    public function test_a_short_gap_stays_one_session(): void
    {
        // 20-minute gap (< 30m) keeps events together.
        $s = vk_group_activity_sessions($this->events([
            ['Created', '09:00'], ['Updated', '09:20'],
        ]));
        $this->assertCount(1, $s);
        $this->assertCount(2, $s[0]['events']);
    }

    public function test_activity_without_a_login_still_forms_a_session(): void
    {
        // Users often don't have a clean Login row (e.g. already-open session).
        $s = vk_group_activity_sessions($this->events([
            ['Created', '09:00'], ['Updated', '09:03'],
        ]));
        $this->assertCount(1, $s);
        $this->assertNull($s[0]['ended']);
    }

    public function test_logout_closes_even_when_more_activity_follows(): void
    {
        $s = vk_group_activity_sessions($this->events([
            ['Login', '09:00'], ['Logout', '09:10'],
            ['Created', '09:15'],
        ]));
        $this->assertCount(2, $s);
        $this->assertSame('logout', $s[0]['ended']);
        $this->assertNull($s[1]['ended']);
        $this->assertCount(1, $s[1]['events']);
    }

    public function test_custom_gap_threshold_is_respected(): void
    {
        $rows = $this->events([['Created', '09:00'], ['Updated', '09:10']]);
        $this->assertCount(1, vk_group_activity_sessions($rows, 1800)); // 30m → together
        $this->assertCount(2, vk_group_activity_sessions($rows, 300));  // 5m  → split
    }
}
