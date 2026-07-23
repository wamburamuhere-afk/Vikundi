<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PR 3 — the money-in/out picture is finished symmetrically.
 *
 * Fines were already cash-correct (only 'paid' fines count as income; there is no
 * approval step to add), so PR 3 does two honest things:
 *   1. Payouts join the cash basis — money-out only when actually 'paid', matching
 *      expenses. (member_payouts is dormant today, so this is zero live impact.)
 *   2. The dashboard now shows the RECEIVABLE — fines owed but not yet collected —
 *      the income-side mirror of the "approved, not yet paid" payable.
 */
class FinesReceivablePayoutTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testPayoutsAreCashBasis(): void
    {
        $fin = $this->src('includes/finance.php');
        // Payouts are money-out only once paid — no longer counted while merely approved.
        $this->assertStringContainsString("FROM member_payouts WHERE status = 'paid'", $fin);
        $this->assertStringNotContainsString("FROM member_payouts WHERE status IN ('approved','paid')", $fin);
    }

    public function testFinesIncomeStillPaidOnly(): void
    {
        // Fines were already correct and must stay that way: a pending (owed) fine
        // is not income; only a collected ('paid') fine is.
        $fin = $this->src('includes/finance.php');
        $this->assertStringContainsString("FROM fines WHERE status = 'paid'", $fin);
    }

    public function testDashboardShowsFinesOwedReceivable(): void
    {
        $dash = $this->src('app/dashboard.php');
        // The pending-fines total (already computed) is now actually displayed...
        $this->assertStringContainsString('$total_pending_fines', $dash);
        $this->assertStringContainsString('dashboard.fines_owed', $dash);
        // ...only to leaders, and only when something is actually owed.
        $this->assertStringContainsString('$total_pending_fines > 0', $dash);
    }

    public function testFinesOwedIsPendingNotWaived(): void
    {
        // "Owed" must exclude waived fines — a waived fine is forgiven, not a debt.
        $dash = $this->src('app/dashboard.php');
        $this->assertStringContainsString("FROM fines WHERE status = 'pending'", $dash);
    }

    public function testFinesOwedLabelTranslatedBothLanguages(): void
    {
        $this->assertStringContainsString('dashboard.fines_owed', $this->src('lang/en.json'));
        $this->assertStringContainsString('dashboard.fines_owed', $this->src('lang/sw.json'));
    }
}
