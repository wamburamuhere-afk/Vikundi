<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Group Financial Ledger used to file the imported M-Koba savings under
 * "Entrance" and compare them to a FULL-YEAR monthly target, so every member
 * showed a large false deficit. The imported amounts are each member's carried-in
 * savings (an opening balance), so the ledger now:
 *   - shows them in their own "Opening (M-Koba)" column,
 *   - never skims the entrance fee off them,
 *   - counts them toward the target (so carrying money in can only REDUCE a
 *     deficit, never cause one),
 *   - and measures the target over months that have ELAPSED, not the whole year.
 * It also fills the previously-blank "M-Koba Name" from the imported rows.
 */
class LedgerOpeningSavingsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/financial_ledger.php');
    }

    public function testImportedContributionsAreIdentifiable(): void
    {
        // The fetch must pull the M-Koba markers so imports can be told apart.
        $this->assertStringContainsString('mkoba_trans_id, account', $this->src);
        $this->assertStringContainsString("!empty(\$c['mkoba_trans_id']) || (\$c['account'] ?? '') === 'M-Koba'", $this->src);
    }

    public function testOpeningIsSeparatedFromNewMoneyAndEntrance(): void
    {
        // Imported money accumulates into $opening, not the new entrance/monthly pool.
        $this->assertStringContainsString('$opening += $c[\'amount\']', $this->src);
        // Entrance fee is taken from NEW money only.
        $this->assertStringContainsString('$entrance_paid = min($new_pool, $entrance_fee_rate)', $this->src);
        $this->assertStringNotContainsString('min($total_raw_pool, $entrance_fee_rate)', $this->src);
    }

    public function testOpeningCountsTowardTargetSoItCannotCauseADeficit(): void
    {
        // Deficit is measured against TOTAL savings (opening + new), so opening can
        // only reduce it — never create one.
        $this->assertStringContainsString('$total_savings = $opening + $new_pool', $this->src);
        $this->assertStringContainsString('$surplus_deficit = $total_savings - $target_amt', $this->src);
    }

    public function testTargetUsesElapsedMonthsNotTheWholeYear(): void
    {
        // A month only counts toward the target once it has actually arrived.
        $this->assertStringContainsString('$this_month = date(\'Y-m-01\')', $this->src);
        $this->assertStringContainsString('($current_col_month <= $this_month)', $this->src);
    }

    public function testOpeningColumnIsRendered(): void
    {
        $this->assertStringContainsString('Opening (M-Koba)', $this->src);
        $this->assertStringContainsString("\$opening > 0 ? fmt(\$opening) : '—'", $this->src);
    }

    public function testMkobaNameFallsBackToImportedName(): void
    {
        // The blank "M-Koba Name" column now falls back to the name on the member's
        // imported contribution rows.
        $this->assertStringContainsString('mkoba_member_name FROM contributions', $this->src);
    }
}
