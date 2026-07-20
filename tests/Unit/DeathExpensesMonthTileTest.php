<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The third KPI tile on the Death Assistance Expenses page was labelled
 * "Cases This Month" (a count) but rendered a money value — the server field
 * `monthTotal` is SUM(amount) for the current month, and the id is
 * `month_payouts`. It also inserted the raw value with no thousands separators
 * and no currency symbol. The tile is now labelled "Paid This Month" and shows
 * a formatted "TSh" amount, consistent with the "Total Assistance" tile.
 */
class DeathExpensesMonthTileTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/accounts/expenses.php');
    }

    public function testMonthTileIsLabelledPaidNotCases(): void
    {
        $this->assertStringContainsString('Paid This Month', $this->src);
        $this->assertStringContainsString('Malipo Mwezi Huu', $this->src);
        // The misleading "count" label must be gone.
        $this->assertStringNotContainsString('Cases This Month', $this->src);
    }

    public function testMonthTotalIsFormattedAsTshMoney(): void
    {
        // monthTotal must be parsed + thousands-separated + TSh-prefixed, the
        // same treatment as totalAmount — not inserted raw.
        $this->assertStringContainsString(
            "\$('#month_payouts').text('TSh ' + parseFloat(json.monthTotal).toLocaleString('en-US', {minimumFractionDigits: 2}));",
            $this->src
        );
        $this->assertStringNotContainsString("\$('#month_payouts').text(json.monthTotal);", $this->src);
    }

    public function testTotalAssistanceTileCarriesTshSymbol(): void
    {
        $this->assertStringContainsString(
            "\$('#total_payouts').text('TSh ' + parseFloat(json.totalAmount).toLocaleString('en-US', {minimumFractionDigits: 2}));",
            $this->src
        );
    }

    public function testStaticMoneyDefaultsShowTsh(): void
    {
        // Pre-AJAX placeholders should already read "TSh 0.00" so there is no
        // flash of an unprefixed / integer value.
        $this->assertStringContainsString('id="total_payouts" style="font-size: 11pt;">TSh 0.00</h5>', $this->src);
        $this->assertStringContainsString('id="month_payouts" style="font-size: 11pt;">TSh 0.00</h5>', $this->src);
    }
}
