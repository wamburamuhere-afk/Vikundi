<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Expense report sidebar charts: a polished proportion doughnut (centre total,
 * custom legend, empty state) and a 6-month trend bar that reuses the already
 * fetched trend data. Source-guards pin the structure so the charts don't
 * silently regress to the old flat doughnut.
 */
class ExpenseReportChartsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/expense_report.php');
    }

    public function testDoughnutIsPolished(): void
    {
        // rounded, spaced, white-bordered segments (not the old borderWidth:0 flat ring)
        $this->assertStringContainsString('borderRadius: 6', $this->src);
        $this->assertStringContainsString('spacing: 3', $this->src);
        $this->assertStringNotContainsString('borderWidth: 0', $this->src);
        // a total drawn in the centre of the doughnut
        $this->assertStringContainsString('centreTotal', $this->src);
        // rich tooltip with value + percentage
        $this->assertStringContainsString("' (' + pct + '%)'", $this->src);
    }

    public function testEmptyStatesGuardTheCharts(): void
    {
        // doughnut only renders when there is spend; otherwise a placeholder
        $this->assertStringContainsString('if ($total_overall > 0):', $this->src);
        // trend only renders when there is data
        $this->assertStringContainsString('if (!empty($trend_data)):', $this->src);
    }

    public function testTrendBarReusesFetchedData(): void
    {
        // the previously-unused trend query is now rendered as a bar chart
        $this->assertStringContainsString("getElementById('trendChart')", $this->src);
        $this->assertStringContainsString("type: 'bar'", $this->src);
        $this->assertStringContainsString('$trend_fmt_labels', $this->src);
        $this->assertStringContainsString('json_encode($trend_values)', $this->src);
    }
}
