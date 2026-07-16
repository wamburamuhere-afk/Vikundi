<?php
/**
 * includes/member_savings.php
 * ---------------------------
 * One authoritative place for a member's savings figures, so the Member Home,
 * the member statement and merge-field letters all show the SAME numbers.
 *
 * Data-model note: contributions and fines key on `customers.customer_id`, which
 * is resolved from the logged-in `users.user_id`. Everything here takes a
 * customer_id, and callers resolve it once via vk_member_customer_id().
 *
 * The savings definition matches app/constant/reports/member_statement.php.
 */

if (!function_exists('vk_member_customer_id')) {
    /** The customer_id for a login user, or null when they have no member record. */
    function vk_member_customer_id(PDO $pdo, int $userId): ?int
    {
        if ($userId <= 0) { return null; }
        $s = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1");
        $s->execute([$userId]);
        $cid = $s->fetchColumn();
        return $cid !== false ? (int) $cid : null;
    }
}

if (!function_exists('vk_member_savings_total')) {
    /** Total confirmed savings (monthly/entrance/other), matching the statement. */
    function vk_member_savings_total(PDO $pdo, int $customerId): float
    {
        if ($customerId <= 0) { return 0.0; }
        $s = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
               FROM contributions
              WHERE member_id = ?
                AND status IN ('confirmed', 'approved', '')
                AND contribution_type IN ('monthly', 'entrance', 'other', '')"
        );
        $s->execute([$customerId]);
        return (float) $s->fetchColumn();
    }
}

if (!function_exists('vk_member_savings_since')) {
    /** Savings from a given date (YYYY-MM-DD) onward — e.g. "this year". */
    function vk_member_savings_since(PDO $pdo, int $customerId, string $sinceDate): float
    {
        if ($customerId <= 0) { return 0.0; }
        $s = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
               FROM contributions
              WHERE member_id = ?
                AND status IN ('confirmed', 'approved', '')
                AND contribution_type IN ('monthly', 'entrance', 'other', '')
                AND contribution_date >= ?"
        );
        $s->execute([$customerId, $sinceDate]);
        return (float) $s->fetchColumn();
    }
}

if (!function_exists('vk_member_monthly_savings')) {
    /**
     * Map of 'YYYY-MM' => total saved that month, over the last $months months.
     * Drives the streak and the mini history.
     */
    function vk_member_monthly_savings(PDO $pdo, int $customerId, int $months = 12): array
    {
        if ($customerId <= 0) { return []; }
        $s = $pdo->prepare(
            "SELECT DATE_FORMAT(contribution_date, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS total
               FROM contributions
              WHERE member_id = ?
                AND status IN ('confirmed', 'approved', '')
                AND contribution_type IN ('monthly', 'entrance', 'other', '')
                AND contribution_date >= (CURDATE() - INTERVAL ? MONTH)
              GROUP BY ym
              ORDER BY ym"
        );
        $s->execute([$customerId, $months]);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

if (!function_exists('vk_member_outstanding_fines')) {
    /** Total unpaid (pending) fines for the member. */
    function vk_member_outstanding_fines(PDO $pdo, int $customerId): float
    {
        if ($customerId <= 0) { return 0.0; }
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE customer_id = ? AND status = 'pending'");
        $s->execute([$customerId]);
        return (float) $s->fetchColumn();
    }
}

// ── Pure streak logic (unit-tested) ──────────────────────────────────────────

if (!function_exists('vk_prev_month')) {
    /** 'YYYY-MM' one month earlier, with year roll-over. */
    function vk_prev_month(string $ym): string
    {
        [$y, $m] = array_map('intval', array_pad(explode('-', $ym), 2, 0));
        $m--;
        if ($m < 1) { $m = 12; $y--; }
        return sprintf('%04d-%02d', $y, $m);
    }
}

if (!function_exists('vk_contribution_streak')) {
    /**
     * Consecutive months (ending now) in which the member saved.
     *
     * The current month being "in progress" must NOT break the streak: if there
     * is no contribution yet this month we start counting from last month.
     *
     * @param array  $monthsWithContribution list of 'YYYY-MM' that have savings
     * @param string $currentYm              the current month, 'YYYY-MM'
     */
    function vk_contribution_streak(array $monthsWithContribution, string $currentYm): int
    {
        $set = array_flip(array_values($monthsWithContribution));
        $ym  = isset($set[$currentYm]) ? $currentYm : vk_prev_month($currentYm);
        $streak = 0;
        while (isset($set[$ym])) {
            $streak++;
            $ym = vk_prev_month($ym);
        }
        return $streak;
    }
}
