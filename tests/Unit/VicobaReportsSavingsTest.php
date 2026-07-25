<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Group Reports page (vicoba_reports) computed Member Savings by summing only
 * contributions with status = 'confirmed'. The M-Koba imports are stored as
 * 'approved', so the whole report read TSh 0 savings and a NEGATIVE cash balance
 * (0 − expenses). The savings must count the same status set the rest of the
 * system treats as valid — 'confirmed', 'approved', or '' — matching the ledger
 * and the dashboard.
 */
class VicobaReportsSavingsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/vicoba_reports.php');
    }

    public function testSavingsCountApprovedContributionsNotOnlyConfirmed(): void
    {
        // No CASE clause may still gate savings on 'confirmed' alone.
        $this->assertStringNotContainsString("co.status='confirmed'", $this->src);
        // The total-savings sum uses the accepted status set (columns are padded).
        $this->assertMatchesRegularExpression(
            "/SUM\\(CASE WHEN co\\.status IN \\('confirmed','approved',''\\)\\s+THEN co\\.amount ELSE 0 END\\),0\\) AS total_savings/",
            $this->src
        );
    }

    public function testPerTypeBucketsAlsoCountApproved(): void
    {
        // Columns are alignment-padded, so match the clause spacing-agnostically.
        foreach (['entrance', 'monthly', 'agm', 'other'] as $type) {
            $this->assertMatchesRegularExpression(
                "/co\\.contribution_type='$type'\\s+AND co\\.status IN \\('confirmed','approved',''\\)/",
                $this->src,
                "the $type bucket should count approved contributions too"
            );
        }
    }
}
