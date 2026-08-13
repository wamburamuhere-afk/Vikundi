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

if (!function_exists('cs_build_schedule')) {
    /**
     * A member's month-by-month contribution schedule — the per-member view shared by
     * the member statement and the profile page (they used to compute this identically
     * in ~60 lines each). Pure: no DB, so it is fully unit-testable.
     *
     * Opening (carried-in M-Koba) counts toward the pot and is never skimmed for the
     * entrance fee; the entrance comes off NEW money only; the pot is laid across the
     * months from the later of the group start and the member's join, up to now. A
     * monthly of 0 means no target (every cell target 0, the whole pot is advance).
     *
     * Returns: opening, new_money, total_paid, entrance_amt, entrance_paid,
     * entrance_status, monthly_amt, monthly_pot, total_months_covered, start_date,
     * anchor_ym, columns_count, distribution[] (label, amount, status, target), advance.
     */
    function cs_build_schedule(
        float $opening,
        float $newMoney,
        float $monthly,
        float $entrance,
        ?string $startDate,
        ?string $joinDate = null,
        ?DateTimeInterface $asOf = null
    ): array {
        $totalPaid      = $opening + $newMoney;
        $entrancePaid   = min($newMoney, $entrance);
        $entranceStatus = $entrancePaid >= $entrance ? 'paid' : ($entrancePaid > 0 ? 'partial' : 'unpaid');
        $monthlyPot     = $opening + max(0.0, $newMoney - $entrancePaid);
        $monthsCovered  = $monthly > 0 ? (int) floor($monthlyPot / $monthly) : 0;

        // Anchor at the later of the group start and the member's own join month.
        $anchorTs = strtotime((string) $startDate);
        if ($anchorTs === false) {
            $anchorTs = strtotime(date('Y') . '-01-01');
        }
        if (!empty($joinDate) && ($j = strtotime($joinDate)) !== false && $j > $anchorTs) {
            $anchorTs = $j;
        }
        $anchorYm = date('Y-m-01', $anchorTs);
        $columns  = max(1, cs_months_elapsed($anchorYm, $asOf), $monthsCovered);

        $distribution = [];
        $remaining = $monthlyPot;
        for ($i = 0; $i < $columns; $i++) {
            $monthTs = strtotime("$i months", strtotime($anchorYm));
            $paid = min($remaining, $monthly);
            $remaining -= $paid;
            $distribution[] = [
                'label'  => date('M Y', $monthTs),
                'amount' => $paid,
                'status' => $paid >= $monthly ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'target' => $monthly,
            ];
        }

        return [
            'opening'              => $opening,
            'new_money'            => $newMoney,
            'total_paid'           => $totalPaid,
            'entrance_amt'         => $entrance,
            'entrance_paid'        => $entrancePaid,
            'entrance_status'      => $entranceStatus,
            'monthly_amt'          => $monthly,
            'monthly_pot'          => $monthlyPot,
            'total_months_covered' => $monthsCovered,
            'start_date'           => $startDate,
            'anchor_ym'            => $anchorYm,
            'columns_count'        => $columns,
            'distribution'         => $distribution,
            'advance'              => max(0.0, $remaining), // money beyond the shown months
        ];
    }
}

if (!function_exists('cs_calendar_grid')) {
    /**
     * Reshape a schedule into the calendar the statements print: one row per YEAR,
     * twelve month columns, NSSF-style. Pure — feed it cs_build_schedule() output.
     *
     * cs_build_schedule() returns a flat run of months starting at the member's own
     * anchor. That is right for the arithmetic and wrong for the page: a member who
     * joined in May must still be shown a January-to-December row, with Jan-Apr
     * visibly NOT owed rather than absent (an absent cell reads as a missed payment
     * to anyone scanning the row, which is the opposite of the truth).
     *
     * Hence six cell states, not three:
     *   before_join — earlier than this member's anchor. Never owed, never a debt.
     *   no_target   — in range, but the group has no fixed monthly rule.
     *   paid        — allocation met the month's target.
     *   partial     — some money landed, short of target. The deficit is target-allocated.
     *   unpaid      — due, nothing allocated.
     *   advance     — beyond "as of", already covered by money paid ahead.
     *   future      — beyond "as of", nothing allocated.
     *
     * A month past "as of" carries target 0 whatever the group rule is: it is not
     * owed yet, and billing it would manufacture a deficit that does not exist. This
     * is the same principle as cs_expected_to_date() counting only elapsed months.
     */
    function cs_calendar_grid(array $schedule, ?DateTimeInterface $asOf = null): array
    {
        $anchorYm     = $schedule['anchor_ym'] ?? date('Y-01-01');
        $distribution = $schedule['distribution'] ?? [];
        $monthly      = (float) ($schedule['monthly_amt'] ?? 0);
        $unallocated  = (float) ($schedule['advance'] ?? 0);

        // cs_build_schedule() covers whole months only (floor), so a payment that is
        // not an exact multiple of the target leaves a remainder smaller than one
        // month sitting outside every column. Lay it on the next month as a partial.
        //
        // This is not cosmetic. Without it the grid totals 280,000 for a member who
        // paid 285,000, so the statement prints a Surplus of 245,000 in the details
        // panel and a Variance of 240,000 in the summary — two figures on one page
        // that must agree, differing by the lost remainder. A treasurer sees that
        // immediately and stops trusting the whole document.
        //
        // Only when a monthly rule exists. With no rule nothing can be laid on any
        // month, and the pot stays genuinely unallocated (reported below).
        if ($monthly > 0 && $unallocated > 0) {
            $distribution[] = [
                'label'  => '',
                'amount' => $unallocated,
                'status' => 'partial',
                'target' => $monthly,
            ];
            $unallocated = 0.0;
        }

        $anchorTs     = strtotime($anchorYm) ?: strtotime(date('Y') . '-01-01');
        $anchorY      = (int) date('Y', $anchorTs);
        $anchorM      = (int) date('n', $anchorTs);

        $asOf   = $asOf ?: new DateTime('today');
        $asOfY  = (int) $asOf->format('Y');
        $asOfM  = (int) $asOf->format('n');

        // The grid runs from the anchor year to whichever ends later: "as of", or the
        // last month money reaches. Paying a year ahead must not truncate the page.
        $lastIndex = count($distribution) - 1;
        $lastY     = $anchorY;
        if ($lastIndex >= 0) {
            $lastY = (int) date('Y', strtotime("$lastIndex months", $anchorTs));
        }
        $lastYear  = max($asOfY, $lastY, $anchorY);

        $years = [];
        for ($y = $anchorY; $y <= $lastYear; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                $index = ($y - $anchorY) * 12 + ($m - $anchorM);
                $due   = ($y < $asOfY) || ($y === $asOfY && $m <= $asOfM);

                if ($index < 0) {
                    $years[$y][$m] = [
                        'target' => 0.0, 'allocated' => 0.0,
                        'status' => 'before_join', 'due' => false,
                    ];
                    continue;
                }

                $cell      = $distribution[$index] ?? null;
                $allocated = (float) ($cell['amount'] ?? 0);
                // Only an elapsed month can carry a target; see the note above.
                $target    = $due ? (float) ($cell['target'] ?? 0) : 0.0;

                if (!$due) {
                    $status = $allocated > 0 ? 'advance' : 'future';
                } elseif ($target <= 0) {
                    $status = 'no_target';
                } elseif ($allocated >= $target) {
                    $status = 'paid';
                } elseif ($allocated > 0) {
                    $status = 'partial';
                } else {
                    $status = 'unpaid';
                }

                $years[$y][$m] = [
                    'target'    => $target,
                    'allocated' => $allocated,
                    'status'    => $status,
                    'due'       => $due,
                ];
            }
        }

        return [
            'years'      => $years,
            'first_year' => $anchorY,
            'last_year'  => $lastYear,
            'anchor_ym'  => $anchorYm,
            'as_of_ym'   => sprintf('%04d-%02d-01', $asOfY, $asOfM),
            // Money the schedule could not lay on any month. With a monthly rule in
            // place this is always 0 (any remainder became a partial month above), so
            // it is non-zero only in the genuine save-what-you-can case.
            'unallocated' => $unallocated,
        ];
    }
}

if (!function_exists('cs_year_summary')) {
    /**
     * The per-year Target vs Actual block printed under the breakdown, plus the
     * grand total. Pure — feed it cs_calendar_grid() output.
     *
     * target   — dues for ELAPSED months only, so it never bills the future.
     * actual   — every shilling laid on that year's months, advance included, so a
     *            member paying ahead reads as a surplus rather than an overpayment.
     * variance — actual - target. Negative is a deficit.
     *
     * total.paid is deliberately NOT the sum of the yearly actuals: when the group
     * has no monthly rule the whole pot is unallocated, every month reads 0, and a
     * naive sum would print "Total 0" for a member who has paid for years. The grand
     * total is what they actually brought in, always.
     */
    function cs_year_summary(array $grid): array
    {
        $years   = [];
        $tTarget = 0.0;
        $tActual = 0.0;

        foreach (($grid['years'] ?? []) as $year => $months) {
            $target = 0.0;
            $actual = 0.0;
            foreach ($months as $cell) {
                $target += (float) $cell['target'];
                $actual += (float) $cell['allocated'];
            }
            $years[$year] = [
                'target'   => $target,
                'actual'   => $actual,
                'variance' => $actual - $target,
            ];
            $tTarget += $target;
            $tActual += $actual;
        }

        $unallocated = (float) ($grid['unallocated'] ?? 0);

        return [
            'years' => $years,
            'total' => [
                'target'      => $tTarget,
                'actual'      => $tActual,
                'variance'    => $tActual - $tTarget,
                'unallocated' => $unallocated,
                'paid'        => $tActual + $unallocated,
            ],
        ];
    }
}

if (!function_exists('cs_member_schedule')) {
    /**
     * cs_build_schedule() for one member, reading everything from the DB: the group's
     * monthly/entrance/start settings (unset => 0 => no target), the member's carried-in
     * initial savings and join date, and the opening-vs-new split of their contributions
     * (the CASE mirrors cs_is_opening(); 'agm' and 'fine' are excluded from savings).
     */
    function cs_member_schedule(PDO $pdo, int $memberId, ?DateTimeInterface $asOf = null): array
    {
        $settings = $pdo->query("SELECT setting_key, setting_value FROM group_settings")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
        $monthly   = (float) ($settings['monthly_contribution'] ?? 0);
        $entrance  = (float) ($settings['entrance_fee'] ?? 0);
        $startDate = $settings['contribution_start_date']
                     ?? ($settings['group_founded_date'] ?? date('Y') . '-01-01');

        $cust = $pdo->prepare("SELECT initial_savings, created_at FROM customers WHERE customer_id = ?");
        $cust->execute([$memberId]);
        $cust = $cust->fetch(PDO::FETCH_ASSOC) ?: ['initial_savings' => 0, 'created_at' => null];

        $split = $pdo->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN (mkoba_trans_id IS NOT NULL AND mkoba_trans_id <> '') OR account = 'M-Koba'
                                THEN amount ELSE 0 END), 0) AS opening,
              COALESCE(SUM(CASE WHEN (mkoba_trans_id IS NULL OR mkoba_trans_id = '') AND (account IS NULL OR account <> 'M-Koba')
                                THEN amount ELSE 0 END), 0) AS newmoney
            FROM contributions
            WHERE member_id = ? AND status IN ('confirmed','approved','')
              AND (contribution_type IN ('monthly','entrance','other') OR contribution_type = '' OR contribution_type IS NULL)");
        $split->execute([$memberId]);
        $split = $split->fetch(PDO::FETCH_ASSOC) ?: ['opening' => 0, 'newmoney' => 0];

        $opening  = (float) $split['opening'];
        $newMoney = (float) ($cust['initial_savings'] ?? 0) + (float) $split['newmoney'];

        return cs_build_schedule($opening, $newMoney, $monthly, $entrance, $startDate, $cust['created_at'] ?? null, $asOf);
    }
}
