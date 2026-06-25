<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the group fund arithmetic (includes/finance.php), audit H1:
 * available fund = (contributions + fines) − (death + general + petty cash + payouts).
 */
class FundBalanceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/finance.php';
    }

    public function testInMinusOut(): void
    {
        // in = 2,400,000 + 5,000 ; out = 100,000 + 50,000 + 0 + 0
        $this->assertSame(2255000.0, fundBalanceFromTotals(2400000, 5000, 100000, 50000, 0, 0));
    }

    public function testFinesCountAsIncome(): void
    {
        $this->assertSame(105000.0, fundBalanceFromTotals(100000, 5000, 0, 0, 0, 0));
    }

    public function testAllOutflowsAreSubtracted(): void
    {
        // 10,000 in − (5,000 + 6,000 + 4,000 + 10,000) = -15,000 (overspent)
        $this->assertSame(-15000.0, fundBalanceFromTotals(10000, 0, 5000, 6000, 4000, 10000));
    }

    public function testEmptyGroupIsZero(): void
    {
        $this->assertSame(0.0, fundBalanceFromTotals(0, 0, 0, 0, 0, 0));
    }
}
