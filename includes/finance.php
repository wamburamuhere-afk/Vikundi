<?php
/**
 * includes/finance.php — group fund balance (single source of truth).
 *
 * Available fund = money in − money out (audit H1):
 *   IN  : approved contributions (all types) + paid fines
 *   OUT : approved death expenses + approved general expenses
 *         + approved petty-cash vouchers + member payouts (approved/paid)
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
            // PR 1: balance-neutral — an expense is money-out whether it is still
            // 'approved' (authorised) or 'paid' (disbursed). PR 2 tightens this to
            // 'paid' only (cash basis) and surfaces the approved-not-paid figure.
            $sum("SELECT COALESCE(SUM(amount),0) FROM death_expenses WHERE status IN ('approved','paid')"),
            $sum("SELECT COALESCE(SUM(amount),0) FROM general_expenses WHERE status IN ('approved','paid')"),
            $sum("SELECT COALESCE(SUM(amount),0) FROM petty_cash_vouchers WHERE status IN ('approved','paid')"),
            $sum("SELECT COALESCE(SUM(amount),0) FROM member_payouts WHERE status IN ('approved','paid')")
        );
    }
}
