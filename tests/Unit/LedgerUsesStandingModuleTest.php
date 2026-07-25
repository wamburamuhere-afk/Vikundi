<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Group Financial Ledger used to carry its OWN copy of the contribution-standing
 * rules — how M-Koba money counts as opening, how the total/deficit/status are derived,
 * and (worst) a `?? 10000` monthly default that fabricated a target and false deficits
 * whenever the group had no monthly set. Those rules now live in one place
 * (includes/contribution_standing.php); the ledger must consume them so it can never
 * drift from the dashboard and Group Reports again.
 */
class LedgerUsesStandingModuleTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/financial_ledger.php');
    }

    public function testItRequiresTheStandingModule(): void
    {
        $this->assertStringContainsString(
            "require_once ROOT_DIR . '/includes/contribution_standing.php'",
            $this->src,
            'the ledger must pull in the shared standing module'
        );
    }

    public function testOpeningDetectionGoesThroughTheModule(): void
    {
        // The old inline `!empty($c['mkoba_trans_id']) || ... === 'M-Koba'` is gone.
        $this->assertStringNotContainsString('$is_imported', $this->src);
        $this->assertStringContainsString('cs_is_opening(', $this->src);
    }

    public function testStandingIsDerivedByTheModule(): void
    {
        // total / surplus_deficit / balance / status come from cs_standing, not local math.
        $this->assertStringContainsString('cs_standing(', $this->src);
        $this->assertStringContainsString("\$surplus_deficit          = \$st['surplus_deficit'];", $this->src);
        $this->assertStringContainsString("\$st['status'] === 'behind'", $this->src);
    }

    public function testNoFabricatedMonthlyTarget(): void
    {
        // A missing monthly must mean "no target", never a hard-coded 10,000.
        $this->assertStringNotContainsString("'monthly_contribution'] ?? 10000", $this->src);
        $this->assertStringContainsString("(float)(\$settings['monthly_contribution'] ?? 0)", $this->src);
    }
}
