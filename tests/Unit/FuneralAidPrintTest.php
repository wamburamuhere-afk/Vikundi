<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Funeral Aid (death) analysis printout. Guards the print correctness fixes:
 * the full case table prints (not just one DataTables page), the comparison
 * chart is included in print, coloured badges survive, and the Fund Impact
 * figure signs itself dynamically instead of a hard-coded minus.
 */
class FuneralAidPrintTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/death_analysis.php');
    }

    public function testFullTablePrintsNotJustOnePage(): void
    {
        // expand to all rows before print, restore after
        $this->assertStringContainsString("addEventListener('beforeprint'", $this->src);
        $this->assertStringContainsString('page.len(-1).draw()', $this->src);
        $this->assertStringContainsString("addEventListener('afterprint'", $this->src);
    }

    public function testComparisonChartIsIncludedInPrint(): void
    {
        // the chart card is no longer screen-only
        $this->assertStringContainsString('<!-- Comparative Chart (screen + print) -->', $this->src);
        $this->assertStringNotContainsString('rounded-4 mb-5 d-print-none', $this->src);
        // it is re-rendered at a fixed paper size for print (crisp, never clipped)
        // and restored to responsive afterwards
        $this->assertStringContainsString('comparisonChart.resize(660, 200)', $this->src);
        $this->assertStringContainsString('comparisonChart.resize();', $this->src);
    }

    public function testChartCapIsPrintOnlyNotScreen(): void
    {
        // the on-screen chart fills its card (professional); the width cap that
        // made it look small must not live on the inline style
        $this->assertStringNotContainsString('max-width:720px', $this->src);
        // page 1 is not left half-empty: big margins are tightened for print
        $this->assertStringContainsString('.mb-4, .mb-5 { margin-bottom: 14px', $this->src);
    }

    public function testPrintFontIsReadable(): void
    {
        // the old tiny 10px base is gone
        $this->assertStringNotContainsString('font-size: 10px', $this->src);
    }

    public function testColouredBadgesSurvivePrint(): void
    {
        $this->assertStringContainsString('.badge { -webkit-print-color-adjust: exact', $this->src);
    }

    public function testFundImpactSignsDynamically(): void
    {
        // no hard-coded leading minus on the Fund Impact figure
        $this->assertStringNotContainsString('text-danger">- TZS <?= number_format($net_fund_impact)', $this->src);
        $this->assertStringContainsString('$impact_sign', $this->src);
        $this->assertStringContainsString('number_format(abs($net_fund_impact))', $this->src);
    }
}
