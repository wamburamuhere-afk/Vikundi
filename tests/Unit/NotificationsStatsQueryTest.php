<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The notifications endpoint (polled for the bell/stats) ran four separate COUNT(*)
 * scans of the notifications table (total / unread / high-priority-unread / today).
 * They're now one conditional-aggregation query — one scan — and parameterised (the
 * old queries interpolated $userId into the SQL).
 */
class NotificationsStatsQueryTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../api/get_notifications.php');
    }

    public function testStatsComeFromOneAggregationQuery(): void
    {
        $this->assertStringContainsString("COALESCE(SUM(is_read = 0), 0)", $this->src);
        $this->assertStringContainsString("COALESCE(SUM(is_read = 0 AND priority = 'high'), 0)", $this->src);
        $this->assertStringContainsString("COALESCE(SUM(DATE(created_at) = CURDATE()), 0)", $this->src);
        $this->assertStringContainsString('FROM notifications WHERE user_id = ?', $this->src);
    }

    public function testSeparateCountQueriesAndInterpolationAreGone(): void
    {
        $this->assertStringNotContainsString('SELECT COUNT(*) FROM notifications WHERE user_id = $userId', $this->src);
    }
}
