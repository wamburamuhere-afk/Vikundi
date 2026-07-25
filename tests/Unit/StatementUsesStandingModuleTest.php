<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Member Financial Statement carried its own `?? 10000` monthly and `?? 20000`
 * entrance defaults, so whenever the group had no monthly/entrance set (the case on
 * the current M-Koba data) it fabricated a target and a false unpaid entrance for
 * every member. It also hand-rolled its own elapsed-months math. Those rules now
 * come from includes/contribution_standing.php so the statement can never drift from
 * the ledger, dashboard and Group Reports.
 */
class StatementUsesStandingModuleTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_statement.php');
    }

    public function testItRequiresTheStandingModule(): void
    {
        $this->assertStringContainsString(
            "require_once __DIR__ . '/../../../includes/contribution_standing.php'",
            $this->src
        );
    }

    public function testNoFabricatedMonthlyOrEntrance(): void
    {
        // A missing monthly/entrance must mean "no target", never a hard-coded amount.
        $this->assertStringNotContainsString("'monthly_contribution'] ?? 10000", $this->src);
        $this->assertStringNotContainsString("'entrance_fee'] ?? 20000", $this->src);
        $this->assertStringContainsString("floatval(\$settings_raw['monthly_contribution'] ?? 0)", $this->src);
        $this->assertStringContainsString("floatval(\$settings_raw['entrance_fee'] ?? 0)", $this->src);
    }

    public function testMonthCountingGoesThroughTheModule(): void
    {
        // The hand-rolled elapsed-months arithmetic is replaced by cs_months_elapsed().
        $this->assertStringContainsString('cs_months_elapsed($anchor_ym)', $this->src);
        $this->assertStringNotContainsString(
            "intval(date('m')) - intval(date('m', \$anchor_ts))",
            $this->src
        );
    }
}
