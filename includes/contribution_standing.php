<?php
/**
 * includes/contribution_standing.php — one source of truth for a member's
 * contribution standing.
 *
 * The ledger, the member statement, the dashboard, the Group Reports and any
 * dormancy sweep all need the same answer: for a member, how much have they
 * brought in, how much is expected of them by now, and are they ahead or behind?
 * Each screen used to re-derive that math, which is why the SAME bug kept
 * reappearing (savings read 0, false year-long deficits, members wrongly
 * dormanted). This module holds the calculation once; the screens call it.
 *
 * Built for an ESTABLISHED group whose history is fed in continuously (Vikundi
 * shadowing M-Koba), so the rules are:
 *   - Money carried in from M-Koba (mkoba_trans_id / account='M-Koba') is an
 *     OPENING balance: it always counts toward the member's total, and is never
 *     treated as an unpaid fresh entrance fee.
 *   - Each member is measured from their OWN first contribution, never a
 *     group-wide start date — so an imported member is not billed for months
 *     that predate their record.
 *   - Only ELAPSED months count toward "expected"; future months are never owed.
 *   - A monthly amount of 0 means "no fixed target" (save-what-you-can): there
 *     are no deficits, and standing is simply the member's savings. This is the
 *     switch the treasurer flips once the real group rule is known — in ONE
 *     place, and every screen follows.
 */

if (!function_exists('cs_is_opening')) {
    /** Is a raw contribution row money carried in from M-Koba (opening savings)? */
    function cs_is_opening(?string $mkobaTransId, ?string $account): bool
    {
        return trim((string) $mkobaTransId) !== '' || $account === 'M-Koba';
    }
}

if (!function_exists('cs_months_elapsed')) {
    /**
     * Whole months from an anchor month up to and including "as of" (min 0).
     * A member whose first contribution is this month has 1 month elapsed.
     */
    function cs_months_elapsed(?string $anchorDate, ?DateTimeInterface $asOf = null): int
    {
        if (empty($anchorDate)) {
            return 0;
        }
        $anchorTs = strtotime($anchorDate);
        if ($anchorTs === false) {
            return 0;
        }
        $asOf = $asOf ?: new DateTime('today');
        $aY = (int) date('Y', $anchorTs);
        $aM = (int) date('n', $anchorTs);
        $bY = (int) $asOf->format('Y');
        $bM = (int) $asOf->format('n');
        $months = ($bY - $aY) * 12 + ($bM - $aM) + 1; // inclusive of the anchor month
        return max(0, $months);
    }
}

if (!function_exists('cs_expected_to_date')) {
    /**
     * Monthly dues expected of a member by now. 0 when there is no fixed monthly
     * amount (save-what-you-can) — the entrance fee is deliberately NOT part of
     * this: an imported member already paid it in the real group.
     */
    function cs_expected_to_date(float $monthly, ?string $anchorDate, ?DateTimeInterface $asOf = null): float
    {
        if ($monthly <= 0) {
            return 0.0;
        }
        return $monthly * cs_months_elapsed($anchorDate, $asOf);
    }
}

if (!function_exists('cs_standing')) {
    /**
     * Assemble a member's standing from their split totals. Opening counts toward
     * the total, so carrying money in can only REDUCE a shortfall, never cause
     * one. Returns a flat array the screens can render directly.
     *
     * status: 'ontrack' when nothing is expected (no fixed target), otherwise
     *         'ahead' when total >= expected, else 'behind'.
     */
    function cs_standing(float $opening, float $newMoney, float $expected, float $assistance = 0.0): array
    {
        $total   = $opening + $newMoney;
        $surplus = $total - $expected;
        return [
            'opening'         => $opening,
            'new'             => $newMoney,
            'total'           => $total,
            'expected'        => $expected,
            'surplus_deficit' => $surplus,
            'balance'         => $total - $assistance,
            'status'          => $expected <= 0 ? 'ontrack' : ($surplus >= 0 ? 'ahead' : 'behind'),
        ];
    }
}

if (!function_exists('cs_group_savings_total')) {
    /**
     * The group's total contributions/savings in one figure — every member's
     * money that counts as savings (fines excluded), across the accepted status
     * set. Uses the SAME rules as cs_group_standing(), so the dashboard's
     * "Contributions" KPI and the Group Reports savings total are guaranteed to
     * agree (both are this number). Cheaper than cs_group_standing() when only the
     * total is needed.
     */
    function cs_group_savings_total(PDO $pdo): float
    {
        // Member-scoped (same join as cs_group_standing) so this equals the sum of
        // every member's standing exactly — orphan contributions whose member_id
        // has no current member are not counted on either side.
        return (float) $pdo->query("
            SELECT COALESCE(SUM(co.amount), 0)
              FROM contributions co
              JOIN customers c ON c.customer_id = co.member_id AND c.status <> 'deleted'
             WHERE co.status IN ('confirmed','approved','')
               AND co.contribution_type <> 'fine'
        ")->fetchColumn();
    }
}

if (!function_exists('cs_group_standing')) {
    /**
     * Every member's standing in one pass, keyed by customer_id. This is what the
     * reports call. Reads the monthly amount from group_settings (0 => no target),
     * splits opening vs new money, and anchors each member at their own first
     * contribution.
     *
     * @return array<int,array> customer_id => cs_standing() row
     */
    function cs_group_standing(PDO $pdo, ?DateTimeInterface $asOf = null): array
    {
        $settings = $pdo->query("SELECT setting_key, setting_value FROM group_settings")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
        $monthly = (float) ($settings['monthly_contribution'] ?? 0);

        $rows = $pdo->query("
            SELECT c.customer_id,
                   COALESCE(SUM(CASE WHEN (co.mkoba_trans_id IS NOT NULL AND co.mkoba_trans_id <> '') OR co.account = 'M-Koba'
                                     THEN co.amount ELSE 0 END), 0) AS opening,
                   COALESCE(SUM(CASE WHEN (co.mkoba_trans_id IS NULL OR co.mkoba_trans_id = '') AND (co.account IS NULL OR co.account <> 'M-Koba')
                                     THEN co.amount ELSE 0 END), 0) AS newmoney,
                   MIN(co.contribution_date) AS first_date
            FROM customers c
            LEFT JOIN contributions co
                   ON c.customer_id = co.member_id
                  AND co.status IN ('confirmed','approved','')
                  AND co.contribution_type <> 'fine'
            WHERE c.status <> 'deleted'
            GROUP BY c.customer_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $expected = cs_expected_to_date($monthly, $r['first_date'], $asOf);
            $out[(int) $r['customer_id']] = cs_standing((float) $r['opening'], (float) $r['newmoney'], $expected)
                + ['first_date' => $r['first_date']];
        }
        return $out;
    }
}
