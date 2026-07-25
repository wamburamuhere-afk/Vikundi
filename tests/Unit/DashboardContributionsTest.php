<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The dashboard "Contributions" KPI now shares one definition with the Group
 * Reports savings total, via the contribution-standing module, so the two can
 * never disagree.
 */
class DashboardContributionsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/dashboard.php');
    }

    public function testContributionsKpiUsesTheStandingModule(): void
    {
        $this->assertStringContainsString('contribution_standing.php', $this->src);
        $this->assertStringContainsString('$total_contributions = cs_group_savings_total($pdo);', $this->src);
        // The old inline sum is gone.
        $this->assertStringNotContainsString(
            "\$total_contributions = (float) \$pdo->query(\"SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status IN ('confirmed', 'approved', '')\")",
            $this->src
        );
    }
}
