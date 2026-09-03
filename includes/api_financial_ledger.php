<?php
/**
 * includes/api_financial_ledger.php — the shared math for the Financial Ledger
 * module (Module 8).
 *
 * Mirrors app/bms/customer/financial_ledger.php row for row: same opening vs.
 * new-money split (cs_is_opening()), same entrance-then-monthly allocation,
 * same "no fixed monthly => no target" rule (cs_standing()), same per-member
 * anchor at the later of the group start date and the member's own join month.
 * Reusing those two shared functions means the API cannot disagree with the
 * web report about what a member has actually paid — only the surrounding
 * per-member loop (entrance/monthly-grid allocation) is reimplemented here,
 * because the web page has never had it extracted into a shared file.
 *
 * getGroupFundBalance() supplies the one figure the web page's line item in
 * todo.md promised ("group fund balance") that the page itself does not show.
 */
require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/contribution_standing.php'; // cs_is_opening(), cs_standing()
require_once __DIR__ . '/finance.php';                // getGroupFundBalance()

if (!function_exists('vk_api_ledger_is_leader')) {
    /** Same gate the web report uses: canView('vicoba_reports'). */
    function vk_api_ledger_is_leader(array $auth): bool
    {
        return vk_api_is_admin((int) ($auth['role_id'] ?? 0))
            || vk_api_can($auth, 'view', 'vicoba_reports');
    }
}

if (!function_exists('vk_api_ledger_require_leader')) {
    function vk_api_ledger_require_leader(array $auth): void
    {
        if (!vk_api_ledger_is_leader($auth)) {
            vk_api_error(403, 'forbidden', 'Only leadership can view the group financial ledger.');
        }
    }
}

if (!function_exists('vk_api_ledger_diff_months')) {
    /**
     * Whole calendar months spanned by two timestamps, inclusive of both ends
     * — mirrors financial_ledger.php's inline arithmetic exactly (line ~73).
     */
    function vk_api_ledger_diff_months(int $ts1, int $ts2): int
    {
        $y1 = (int) date('Y', $ts1);
        $y2 = (int) date('Y', $ts2);
        $m1 = (int) date('m', $ts1);
        $m2 = (int) date('m', $ts2);
        return (($y2 - $y1) * 12) + ($m2 - $m1) + 1;
    }
}

if (!function_exists('vk_api_ledger_period')) {
    /**
     * Validate and default the reporting period from query params.
     *
     * Defaults to the current calendar year, same as financial_ledger.php.
     * Capped at 10 years (120 months): the grid allocates one array slot per
     * elapsed month per member, so an unbounded range is a way to force a
     * huge response — a validation the web page does not need behind its own
     * date picker, but a public query parameter is exactly the boundary this
     * project's conventions say to validate at.
     *
     * @return array{0:string,1:string} [start_date, end_date]
     */
    function vk_api_ledger_period(array $q): array
    {
        $start = trim((string) ($q['start_date'] ?? '')) ?: date('Y-01-01');
        $end   = trim((string) ($q['end_date'] ?? '')) ?: date('Y-12-31');

        foreach (['start_date' => $start, 'end_date' => $end] as $label => $raw) {
            $d = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw) {
                vk_api_error(422, 'invalid_date', $label . ' must be a date in YYYY-MM-DD format.');
            }
        }

        $ts1 = strtotime($start);
        $ts2 = strtotime($end);
        if ($ts2 < $ts1) {
            vk_api_error(422, 'invalid_range', 'end_date must not be before start_date.');
        }
        if (vk_api_ledger_diff_months($ts1, $ts2) > 120) {
            vk_api_error(422, 'range_too_large', 'The period between start_date and end_date must not exceed 120 months.');
        }

        return [$start, $end];
    }
}

if (!function_exists('vk_api_ledger_member_calc')) {
    /**
     * The per-member split, as pure arithmetic — no DB, directly unit-testable.
     * Mirrors financial_ledger.php lines 232-293.
     *
     * @param bool[] $colIsValid one flag per elapsed-month column: does this
     *        column fall within the group's start date, the member's own join
     *        month, and on/before today?
     */
    function vk_api_ledger_member_calc(
        float $opening,
        float $newPool,
        float $agmPaid,
        float $assistance,
        float $monthlyRate,
        float $entranceFeeRate,
        array $colIsValid,
        int $validMonthsCount
    ): array {
        // Entrance fee comes off NEW money first — never off the opening balance.
        $entrancePaid = min($newPool, $entranceFeeRate);
        $remaining    = $newPool - $entrancePaid;

        // With a fixed monthly, chunk the new money in monthly-sized pieces; with
        // no fixed monthly (save-what-you-can), spread it evenly across the
        // elapsed months so it still reads across the row.
        $gridCap = $monthlyRate > 0
            ? $monthlyRate
            : ($validMonthsCount > 0 ? $remaining / $validMonthsCount : $remaining);

        $columns        = count($colIsValid);
        $monthlyTotal   = 0.0;
        $monthlyByMonth = array_fill(0, $columns, 0.0);
        foreach ($colIsValid as $i => $valid) {
            if ($valid && $remaining > 0 && $gridCap > 0) {
                $allocation = min($remaining, $gridCap);
                $monthlyByMonth[$i] = $allocation;
                $monthlyTotal += $allocation;
                $remaining -= $allocation;
            }
        }
        if ($remaining > 0 && $columns > 0) {
            $monthlyByMonth[$columns - 1] += $remaining;
            $monthlyTotal += $remaining;
        }

        // No fixed monthly => no target, so an unset monthly can never fabricate
        // a deficit (same rule cs_standing() itself encodes).
        $targetAmt = $monthlyRate * $validMonthsCount;
        $standing  = cs_standing($opening, $newPool, $targetAmt, $assistance);

        return [
            'entrance_paid'            => $entrancePaid,
            'monthly_by_month'         => $monthlyByMonth,
            'monthly_total'            => $monthlyTotal,
            'target_amt'               => $targetAmt,
            'total_member_contributed' => $standing['total'] + $agmPaid,
            'balance'                  => $standing['balance'],
            'surplus_deficit'          => $standing['surplus_deficit'],
            'status'                   => $standing['status'],
        ];
    }
}

if (!function_exists('vk_api_ledger_build')) {
    /**
     * Every member's ledger row for a period, plus grand totals.
     *
     * @return array{months:array,rows:array,totals:array}
     */
    function vk_api_ledger_build(PDO $pdo, string $startDate, string $endDate): array
    {
        $settings = $pdo->query('SELECT setting_key, setting_value FROM group_settings')
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
        // No fixed monthly => no target (save-what-you-can), matching the web report.
        $monthlyRate            = (float) ($settings['monthly_contribution'] ?? 0);
        $entranceFeeRate        = (float) ($settings['entrance_fee'] ?? 0);
        $contributionStartDate  = $settings['contribution_start_date'] ?? null;

        $ts1        = strtotime($startDate);
        $ts2        = strtotime($endDate);
        $diffMonths = vk_api_ledger_diff_months($ts1, $ts2);

        $months = [];
        for ($i = 0; $i < $diffMonths; $i++) {
            $mts = strtotime("+{$i} months", $ts1);
            $months[] = ['ym' => date('Y-m', $mts), 'label' => date('M Y', $mts)];
        }

        // The "M-Koba Name" falls back to the name captured on an imported
        // contribution row when the one-off import never populated it on the
        // member (see financial_ledger.php).
        $members = $pdo->query("
            SELECT c.customer_id, c.first_name, c.last_name,
                   COALESCE(NULLIF(c.mpesa_name, ''),
                            (SELECT mkoba_member_name FROM contributions
                              WHERE member_id = c.customer_id AND mkoba_member_name IS NOT NULL AND mkoba_member_name <> ''
                              LIMIT 1)) AS mpesa_name,
                   c.status, c.created_at
            FROM customers c
            WHERE c.status != 'deleted'
            ORDER BY c.first_name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("
            SELECT member_id, amount, contribution_type, contribution_date, mkoba_trans_id, account
            FROM contributions
            WHERE status IN ('confirmed', 'approved', '')
            AND contribution_date BETWEEN ? AND ?");
        $st->execute([$startDate, $endDate]);
        $contributions = $st->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

        $st = $pdo->prepare("
            SELECT member_id, SUM(amount) as total_assistance
            FROM member_payouts
            WHERE status = 'paid'
            AND payout_date BETWEEN ? AND ?
            GROUP BY member_id");
        $st->execute([$startDate, $endDate]);
        $payouts = $st->fetchAll(PDO::FETCH_KEY_PAIR);

        $thisMonth = date('Y-m-01');

        $rows = [];
        $totals = [
            'members' => 0, 'opening' => 0.0, 'entrance' => 0.0, 'monthly' => 0.0,
            'contributed' => 0.0, 'assistance' => 0.0, 'agm' => 0.0, 'balance' => 0.0,
            'target' => 0.0, 'surplus_deficit' => 0.0,
        ];

        foreach ($members as $m) {
            $mid = (int) $m['customer_id'];
            $memberContribs = $contributions[$mid] ?? [];

            $opening = 0.0;
            $newPool = 0.0;
            $agmPaid = 0.0;
            foreach ($memberContribs as $c) {
                if ($c['contribution_type'] === 'agm') {
                    $agmPaid += (float) $c['amount'];
                } elseif (cs_is_opening($c['mkoba_trans_id'] ?? null, $c['account'] ?? null)) {
                    $opening += (float) $c['amount'];
                } elseif ($c['contribution_type'] === 'entrance' || $c['contribution_type'] === 'monthly') {
                    $newPool += (float) $c['amount'];
                }
            }

            // A member is only owed a target from the month they JOINED — imported
            // members carrying an opening balance aren't charged for months before them.
            $joinMonth  = !empty($m['created_at']) ? date('Y-m-01', strtotime($m['created_at'])) : null;
            $startMonth = $contributionStartDate ? date('Y-m-01', strtotime($contributionStartDate)) : null;

            $colIsValid = [];
            $validMonthsCount = 0;
            for ($i = 0; $i < $diffMonths; $i++) {
                $col = date('Y-m-01', strtotime("+{$i} months", $ts1));
                $ok  = ($col <= $thisMonth)
                    && (!$startMonth || $col >= $startMonth)
                    && (!$joinMonth || $col >= $joinMonth);
                $colIsValid[$i] = $ok;
                if ($ok) {
                    $validMonthsCount++;
                }
            }

            $assistance = (float) ($payouts[$mid] ?? 0);
            $calc = vk_api_ledger_member_calc(
                $opening, $newPool, $agmPaid, $assistance,
                $monthlyRate, $entranceFeeRate, $colIsValid, $validMonthsCount
            );

            $rows[] = [
                'member_id'         => $mid,
                'member_name'       => trim($m['first_name'] . ' ' . $m['last_name']),
                'mkoba_name'        => $m['mpesa_name'] ?: null,
                'status'            => (string) $m['status'],
                'opening'           => $opening,
                'entrance_paid'     => $calc['entrance_paid'],
                'monthly_by_month'  => $calc['monthly_by_month'],
                'monthly_total'     => $calc['monthly_total'],
                'total_contributed' => $calc['total_member_contributed'],
                'assistance'        => $assistance,
                'agm_paid'          => $agmPaid,
                'balance'           => $calc['balance'],
                'target'            => $calc['target_amt'],
                'valid_months'      => $validMonthsCount,
                'surplus_deficit'   => $calc['surplus_deficit'],
                'standing'          => $calc['status'],
            ];

            $totals['members']++;
            $totals['opening']         += $opening;
            $totals['entrance']        += $calc['entrance_paid'];
            $totals['monthly']         += $calc['monthly_total'];
            $totals['contributed']     += $calc['total_member_contributed'];
            $totals['assistance']      += $assistance;
            $totals['agm']             += $agmPaid;
            $totals['balance']         += $calc['balance'];
            $totals['target']          += $calc['target_amt'];
            $totals['surplus_deficit'] += $calc['surplus_deficit'];
        }

        return ['months' => $months, 'rows' => $rows, 'totals' => $totals];
    }
}
