<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Member Analysis report. Regression guards for the data-truth fixes (rejected
 * applicants excluded, last-6-months growth window, region % over the region
 * total with blank states labelled) and the print polish (readable font,
 * charts re-rendered at paper size, empty states).
 */
class MemberAnalysisTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/customer_analysis.php');
    }

    public function testRejectedApplicantsExcludedFromMembers(): void
    {
        $this->assertStringContainsString("status NOT IN ('deleted', 'rejected')", $this->src);
        // the old too-broad total that counted rejected users is gone
        $this->assertStringNotContainsString("user_role != 'Admin' AND status != 'deleted'\")", $this->src);
    }

    public function testGrowthUsesLatestSixMonths(): void
    {
        // latest 6 (DESC) then re-sorted chronologically — not the earliest 6
        $this->assertStringContainsString('ORDER BY month DESC', $this->src);
        $this->assertStringContainsString('ORDER BY month ASC', $this->src);
    }

    public function testRegionPercentageUsesRegionTotalAndLabelsBlanks(): void
    {
        $this->assertStringContainsString('$region_total', $this->src);
        $this->assertStringContainsString('$region_counts[$i] / $region_total', $this->src);
        // blank states are labelled, not shown as an empty/N-A slice
        $this->assertStringContainsString('Unspecified', $this->src);
        // percentage no longer divided by the (wrong) member count
        $this->assertStringNotContainsString("(\$r['count'] / \$total_members)", $this->src);
    }

    public function testEmptyStatesAndReadablePrint(): void
    {
        $this->assertStringContainsString('No region data yet', $this->src);
        $this->assertStringContainsString('No growth data yet', $this->src);
        // the tiny 10px print base is gone
        $this->assertStringNotContainsString('font-size: 10px', $this->src);
    }

    public function testChartsReRenderForPrint(): void
    {
        $this->assertStringContainsString('growthChart.resize(460, 170)', $this->src);
        $this->assertStringContainsString('regionChart.resize(220, 220)', $this->src);
    }
}
