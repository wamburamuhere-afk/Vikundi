<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The dashboard's 6-month trend used to run a PHP loop that fired two queries per
 * month — 12 round-trips for the same result. It now uses two GROUP BY queries (one
 * scan per dataset) that fill pre-seeded month buckets. Same numbers, far fewer trips.
 */
class DashboardTrendQueryTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/dashboard.php');
    }

    public function testTrendUsesGroupedQueries(): void
    {
        $this->assertStringContainsString("DATE_FORMAT(contribution_date, '%Y-%m')", $this->src);
        $this->assertStringContainsString('GROUP BY ym', $this->src);
        // Expenses are summed with one UNION ALL scan, not a per-month subquery pair.
        $this->assertStringContainsString('UNION ALL', $this->src);
    }

    public function testPerMonthLoopQueriesAreGone(): void
    {
        // The old per-iteration queries (placeholder YEAR/MONTH on each pass) must be gone.
        $this->assertStringNotContainsString('YEAR(contribution_date)=? AND MONTH(contribution_date)=?', $this->src);
        $this->assertStringNotContainsString('YEAR(expense_date)=? AND MONTH(expense_date)=?', $this->src);
    }
}
