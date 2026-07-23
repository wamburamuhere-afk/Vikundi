<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PR 2 — cash basis for expenses. The group balance now counts an expense as
 * money-out only once it is *paid* (disbursed); an authorised-but-unpaid expense
 * is surfaced separately as the "approved, not yet paid" commitment. A one-time,
 * future-safe backfill marks pre-cutover approved expenses as paid so the flip is
 * balance-neutral at deploy. Because 'paid' is a substate of 'approved', every
 * report/aggregate that meant "a real expense" now reads status IN ('approved','paid').
 */
class ExpensesCashBasisTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testBalanceCountsOnlyPaidExpenses(): void
    {
        $fin = $this->src('includes/finance.php');
        // Cash basis: each expense table is money-out only when paid.
        $this->assertStringContainsString("FROM death_expenses WHERE status = 'paid'", $fin);
        $this->assertStringContainsString("FROM general_expenses WHERE status = 'paid'", $fin);
        $this->assertStringContainsString("FROM petty_cash_vouchers WHERE status = 'paid'", $fin);
        // It must NOT still count approved expenses as out.
        $this->assertStringNotContainsString("FROM death_expenses WHERE status IN ('approved','paid')", $fin);
    }

    public function testApprovedNotYetPaidHelperSumsApprovedOnly(): void
    {
        require_once __DIR__ . '/../../includes/finance.php';
        $this->assertTrue(function_exists('approvedNotYetPaidExpenses'));
        $fin = $this->src('includes/finance.php');
        // The commitments figure is approved-only across the three expense tables.
        $this->assertStringContainsString("FROM death_expenses WHERE status = 'approved'", $fin);
        $this->assertStringContainsString("FROM general_expenses WHERE status = 'approved'", $fin);
        $this->assertStringContainsString("FROM petty_cash_vouchers WHERE status = 'approved'", $fin);
    }

    public function testBackfillIsFutureSafeAndRegistered(): void
    {
        $mig = $this->src('database/backfill_approved_expenses_paid.php');
        // Moves approved -> paid ...
        $this->assertStringContainsString("status  = 'paid'", $mig);
        $this->assertStringContainsString("WHERE status = 'approved'", $mig);
        // ... but ONLY for rows approved before a fixed cutover, so re-running on a
        // later deploy can never sweep up freshly-approved expenses.
        $this->assertStringContainsString(':cutover', $mig);
        $this->assertMatchesRegularExpression('/COALESCE\([^)]*created_at\)\s*<\s*:cutover/', $mig);
        // Backfills the paid audit trail from the approval record, non-destructively.
        $this->assertStringContainsString('COALESCE(paid_at', $mig);
        $this->assertStringContainsString('COALESCE(paid_by, approved_by)', $mig);
        // Wired into the migration chain after the enum was added.
        $migrate = $this->src('database/migrate.php');
        $this->assertStringContainsString('backfill_approved_expenses_paid.php', $migrate);
        $this->assertGreaterThan(
            strpos($migrate, 'add_paid_status_to_expenses.php'),
            strpos($migrate, 'backfill_approved_expenses_paid.php'),
            'backfill must run after the paid enum/columns exist'
        );
    }

    public function testBackfillTouchesAllThreeExpenseTables(): void
    {
        $mig = $this->src('database/backfill_approved_expenses_paid.php');
        $this->assertStringContainsString("'death_expenses'", $mig);
        $this->assertStringContainsString("'general_expenses'", $mig);
        $this->assertStringContainsString("'petty_cash_vouchers'", $mig);
        // Petty cash names its approval timestamp differently.
        $this->assertStringContainsString('approval_date', $mig);
    }

    public function testDashboardSurfacesTheCommitmentFigure(): void
    {
        $dash = $this->src('app/dashboard.php');
        $this->assertStringContainsString('approvedNotYetPaidExpenses($pdo)', $dash);
        $this->assertStringContainsString('dashboard.approved_not_paid', $dash);
        // Only shown to leaders when there is actually something unpaid.
        $this->assertStringContainsString('$approved_not_paid > 0', $dash);
        // The translation key exists in both languages.
        $this->assertStringContainsString('dashboard.approved_not_paid', $this->src('lang/en.json'));
        $this->assertStringContainsString('dashboard.approved_not_paid', $this->src('lang/sw.json'));
    }

    /**
     * A paid expense is still an authorised expense, so every "total expenses"
     * aggregate must include it — otherwise reports would lose all history the
     * moment the backfill flips it to paid.
     */
    public function testExpenseAggregatesCountApprovedOrPaid(): void
    {
        foreach ([
            'app/dashboard.php',
            'app/constant/reports/expense_report.php',
            'app/constant/reports/vicoba_reports.php',
            'app/constant/reports/member_statement.php',
            'app/constant/accounts/petty_cash.php',
            'api/get_general_expenses.php',
            'api/get_member_death_history.php',
            'core/ai_insights.php',
        ] as $f) {
            $src = $this->src($f);
            $this->assertStringContainsString(
                "IN ('approved','paid')",
                $src,
                "$f should count expenses as approved-or-paid, not approved-only"
            );
        }
    }

    public function testDeleteGuardStillBlocksPaidExpenses(): void
    {
        // A paid expense is even more final than an approved one — deleting either
        // must be impossible (only pending/rejected can be removed).
        $del = $this->src('api/delete_general_expense.php');
        $this->assertStringContainsString("status NOT IN ('approved','paid')", $del);
        $this->assertStringNotContainsString("status != 'approved'", $del);
    }

    public function testApprovalMessagesNoLongerClaimBalanceReduced(): void
    {
        // Under cash basis, approving does not move the balance — only paying does.
        foreach (['actions/approve_death_expense.php', 'api/approve_general_expense.php'] as $f) {
            $src = $this->src($f);
            $this->assertStringContainsString('marked paid', $src, "$f approval message should point to the paid step");
            $this->assertStringNotContainsString('balance updated', $src, "$f must not claim the balance was reduced on approval");
        }
    }
}
