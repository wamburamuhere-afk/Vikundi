<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The dashboard read the three member counts (total / active / pending) with three
 * separate COUNT(*) scans of the `users` table. They now come from one conditional
 * aggregation query — one scan, same numbers.
 */
class DashboardUserCountsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/dashboard.php');
    }

    public function testCountsComeFromOneConditionalAggregation(): void
    {
        $this->assertStringContainsString("SUM(status <> 'deleted' AND user_role <> 'Admin'), 0) AS total", $this->src);
        $this->assertStringContainsString("SUM(status =  'active'  AND user_role <> 'Admin'), 0) AS active", $this->src);
        $this->assertStringContainsString("SUM(status =  'pending'), 0)", $this->src);
    }

    public function testSeparatePerCountQueriesAreGone(): void
    {
        $this->assertStringNotContainsString("SELECT COUNT(*) FROM users WHERE status = 'active' AND user_role != 'Admin'", $this->src);
        $this->assertStringNotContainsString("SELECT COUNT(*) FROM users WHERE status = 'pending'", $this->src);
    }
}
