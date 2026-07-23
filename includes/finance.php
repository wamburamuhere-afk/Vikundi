<?php
/**
 * includes/finance.php — group fund balance (single source of truth).
 *
 * Available fund = money in − money out (audit H1), on a CASH basis:
 *   IN  : approved contributions (all types) + paid fines
 *   OUT : paid death expenses + paid general expenses
 *         + paid petty-cash vouchers + paid member payouts
 * An expense/payout that is merely 'approved' is an authorised commitment that
 * hasn't left the account yet — see approvedNotYetPaidExpenses().
 *
 * Derived from the live records so it can never drift. The old stored
 * group_settings.group_balance was only ever decremented (never credited by
 * contributions), so it diverged from reality and from the dashboard.
 */

if (!function_exists('fundBalanceFromTotals')) {
    /**
     * Pure arithmetic for the fund balance (unit-testable, no DB).
     */
    function fundBalanceFromTotals(
        float $contributionsIn,
        float $finesIn,
        float $deathExpensesOut,
        float $generalExpensesOut,
        float $pettyCashOut,
        float $payoutsOut
    ): float {
        $in  = $contributionsIn + $finesIn;
        $out = $deathExpensesOut + $generalExpensesOut + $pettyCashOut + $payoutsOut;
        return $in - $out;
    }
}

if (!function_exists('getGroupFundBalance')) {
    /**
     * Current available group fund, computed from the live records.
     */
    function getGroupFundBalance(PDO $pdo): float
    {
        $sum = static function (string $sql) use ($pdo): float {
            try {
                return (float) $pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                return 0.0; // table/column absent on some environments -> treat as 0
            }
        };

        return fundBalanceFromTotals(
            $sum("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status IN ('approved','confirmed','')"),
            $sum("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'paid'"),
            // PR 2 — cash basis: an expense only leaves the balance once it is
            // actually 'paid' (disbursed). An 'approved' expense is an authorised
            // commitment that hasn't left the account yet — surfaced separately as
            // the "approved, not yet paid" figure (see approvedNotYetPaidExpenses()).
            $sum("SELECT COALESCE(SUM(amount),0) FROM death_expenses WHERE status = 'paid'"),
            $sum("SELECT COALESCE(SUM(amount),0) FROM general_expenses WHERE status = 'paid'"),
            $sum("SELECT COALESCE(SUM(amount),0) FROM petty_cash_vouchers WHERE status = 'paid'"),
            // Payouts are cash basis too: money-out only once actually paid
            // (disbursed), consistent with expenses above. member_payouts is a
            // dormant/unrouted feature today, so this is zero live impact — it just
            // keeps the balance correct if payouts are ever switched on.
            $sum("SELECT COALESCE(SUM(amount),0) FROM member_payouts WHERE status = 'paid'")
        );
    }
}

if (!function_exists('approvedNotYetPaidExpenses')) {
    /**
     * Expenses authorised but not yet disbursed — the commitment sitting against
     * the balance. This is exactly the money the cash-basis balance does NOT yet
     * deduct, so leaders can see it plainly instead of it hiding the gap.
     */
    function approvedNotYetPaidExpenses(PDO $pdo): float
    {
        $sum = static function (string $sql) use ($pdo): float {
            try {
                return (float) $pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                return 0.0;
            }
        };

        return $sum("SELECT COALESCE(SUM(amount),0) FROM death_expenses WHERE status = 'approved'")
             + $sum("SELECT COALESCE(SUM(amount),0) FROM general_expenses WHERE status = 'approved'")
             + $sum("SELECT COALESCE(SUM(amount),0) FROM petty_cash_vouchers WHERE status = 'approved'");
    }
}
