<?php
/**
 * includes/api_transactions.php — the shared rules for the Transactions module.
 *
 * WHAT A "TRANSACTION" IS HERE, AND WHY IT IS NOT A CONTRIBUTION.
 *
 * Both read the same `contributions` table. They differ in what a row MEANS:
 *
 *   /contributions  — money by the month it COVERS. One 100,000 payment in
 *                     January is five covered months.
 *   /transactions   — money by the date it ARRIVED. That same payment is a
 *                     single January event.
 *
 * The group asked for both documents and they legitimately disagree year by
 * year. What must never disagree is the GRAND TOTAL, which is why the member's
 * own view is built from cs_member_transactions() and cs_transaction_grid() —
 * the same functions the web statement uses, over the same
 * cs_statement_filter_sql() the schedule sums. A second query written by hand
 * here would be the drift.
 *
 * The group view additionally mirrors the M-Koba statement 1:1, which is the
 * other reason this module exists: /contributions does not carry the mkoba_*
 * columns, and without them a treasurer cannot tie the books to the statement.
 */

require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/api_contributions.php';   // vk_api_contrib_row(), scoping, accounts
require_once __DIR__ . '/contribution_standing.php';

if (!function_exists('vk_api_txn_row')) {
    /**
     * A contributions row as a TRANSACTION: everything /contributions publishes,
     * plus the M-Koba statement columns.
     *
     * Built on vk_api_contrib_row() rather than beside it so the money, status,
     * is_opening and counts_toward_savings fields cannot mean one thing on one
     * endpoint and something else on the other.
     */
    function vk_api_txn_row(array $r): array
    {
        $row = vk_api_contrib_row($r);

        // The statement's own columns. Null rather than '' when absent: a row
        // recorded in Vikundi rather than imported from M-Koba has no S/No, and
        // an empty string would render as a blank cell that looks like data.
        $row['mkoba'] = [
            'sno'           => vk_api_txn_str($r['mkoba_sno'] ?? null),
            'trans_id'      => vk_api_txn_str($r['mkoba_trans_id'] ?? null),
            'member_id_str' => vk_api_txn_str($r['mkoba_member_id_str'] ?? null),
            'source'        => vk_api_txn_str($r['mkoba_source'] ?? null),
            'destination'   => vk_api_txn_str($r['mkoba_destination'] ?? null),
            'trans_type'    => vk_api_txn_str($r['mkoba_trans_type'] ?? null),
        ];

        return $row;
    }
}

if (!function_exists('vk_api_txn_str')) {
    /** Trimmed string, or null when there is nothing there. */
    function vk_api_txn_str($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s !== '' ? $s : null;
    }
}

if (!function_exists('vk_api_txn_require_leader')) {
    /**
     * GET /transactions is the whole group's money, so it is leadership only —
     * a hard 403, not a narrowing to the caller's own rows.
     *
     * A member's own transactions live at /my/transactions. Two endpoints rather
     * than one that quietly changes meaning: api/get_transactions.php was gated
     * on canView(), which is the grant an ordinary MEMBER holds, and every
     * signed-in member could pull all 333 rows of the group's history. The rule
     * that `edit` is the leadership test lives in includes/contribution_access.php
     * and is shared with the web so the two cannot drift again.
     */
    function vk_api_txn_require_leader(array $auth): void
    {
        if (!vk_api_contrib_is_leader($auth)) {
            vk_api_error(
                403,
                'forbidden',
                'Group financial records are available to leadership only. '
                . 'Your own transactions are at /api/v1/my/transactions.'
            );
        }
    }
}

if (!function_exists('vk_api_txn_filters')) {
    /**
     * The query filters both list endpoints accept, validated into [sql, params].
     *
     * Dates are parsed rather than passed through: an unparseable string would
     * otherwise become a silent full-table scan that ignores the filter the user
     * asked for and shows them the wrong figures without saying so.
     *
     * @param array $q      the request query ($_GET)
     * @param string $alias table alias for the contributions table
     * @return array{0:string[],1:array}
     */
    function vk_api_txn_filters(array $q, string $alias = 'co'): array
    {
        $a = rtrim($alias, '.') . '.';
        $where  = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, vk_api_contrib_statuses(), true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: '
                    . implode(', ', vk_api_contrib_statuses()) . '.');
            }
            $where[]  = "{$a}status = ?";
            $params[] = $status;
        }

        $type = trim((string) ($q['type'] ?? ''));
        if ($type !== '') {
            if (!in_array($type, vk_api_contrib_types(), true)) {
                vk_api_error(422, 'invalid_type', 'type must be one of: '
                    . implode(', ', vk_api_contrib_types()) . '.');
            }
            $where[]  = "{$a}contribution_type = ?";
            $params[] = $type;
        }

        // The filter /contributions does not have. Money arrives through M-Koba,
        // a bank, cash or mobile money, and reconciling means looking at one
        // channel at a time.
        $account = trim((string) ($q['account'] ?? ''));
        if ($account !== '') {
            if (!in_array($account, vk_api_contrib_accounts(), true)) {
                vk_api_error(422, 'invalid_account', 'account must be one of: '
                    . implode(', ', vk_api_contrib_accounts()) . '.');
            }
            $where[]  = "{$a}account = ?";
            $params[] = $account;
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            $raw = trim((string) ($q[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $d = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw) {
                vk_api_error(422, 'invalid_date', $key . ' must be a date in YYYY-MM-DD format.');
            }
            $where[]  = "{$a}contribution_date {$op} ?";
            $params[] = $raw;
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_txn_received_total')) {
    /**
     * What the member has actually brought in: the brought-forward opening plus
     * every dated receipt.
     *
     * customers.initial_savings CARRIES NO DATE, so cs_transaction_grid() — which
     * buckets money by the month it arrived — cannot place it in any month and
     * cs_member_transactions() never returns it. cs_member_schedule() does count
     * it, so a statement built only from dated receipts falls short of
     * /contributions/standing's total_saved by exactly the member's carried-in
     * savings, and the two documents disagree.
     *
     * app/constant/reports/member_transactions.php already solved this: it shows
     * the carried-in amount as a brought-forward opening line, the way a bank
     * statement does with a balance carried in. This is that same sum, so the
     * grand totals agree by construction rather than by luck.
     *
     * Verified live on demo member 30: 20,000 carried in + 420,000 in dated
     * receipts = the 440,000 /contributions/standing reports.
     */
    function vk_api_txn_received_total(float $openingBroughtForward, array $summary): float
    {
        // 'actual', matching the web statement. On a transaction grid
        // 'unallocated' is always 0, so 'paid' would give the same figure — but
        // only 'actual' is the sum the web prints.
        return $openingBroughtForward + (float) ($summary['total']['actual'] ?? 0);
    }
}
