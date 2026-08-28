<?php
/**
 * includes/api_fines.php — the shared rules for the Fines module.
 *
 * WHO SEES WHAT, AND WHY IT IS NOT THE CONTRIBUTIONS RULE.
 *
 * Fines are deliberately more open than contributions. app/bms/customer/
 * my_fines.php gives ANY member a ?view=all toggle onto every fine in the group,
 * because the group asked for it: it is the same disclosure the Group Financial
 * Ledger already makes, which shows any member every other member's
 * contributions and shortfall. That is a decision the group took, not a leak,
 * and the API mirrors it rather than quietly being stricter than the website.
 *
 * What stays closed is WRITING. Recording, editing, waiving and marking paid are
 * leadership acts, gated on manage_fines.
 *
 * The leadership test is `edit`, never `view` — the same rule as contributions.
 * `manage_fines` happens not to be granted to Member on the current data, so
 * `view` would pass today; it is not the test because that is a fact about the
 * data, not about the rule, and one reasonable-sounding grant would undo it.
 */

require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/fine_helpers.php';

if (!function_exists('vk_api_fines_is_leader')) {
    /** May this caller manage the group's fines? */
    function vk_api_fines_is_leader(array $auth): bool
    {
        return vk_api_is_admin((int) ($auth['role_id'] ?? 0))
            || vk_api_can($auth, 'edit', 'manage_fines');
    }
}

if (!function_exists('vk_api_fines_require_leader')) {
    /** Refuse anyone who may not manage fines. */
    function vk_api_fines_require_leader(array $auth, string $what = 'manage fines'): void
    {
        if (!vk_api_fines_is_leader($auth)) {
            vk_api_error(403, 'forbidden', "You do not have permission to {$what}.");
        }
    }
}

if (!function_exists('vk_api_fine_row')) {
    /**
     * One fine, as the app renders it.
     *
     * `is_outstanding` is answered here rather than left to the client: only
     * 'pending' is money still owed. 'waived' is forgiven and 'paid' is
     * collected, and a client summing every row would tell a member they owe
     * money they have already settled.
     */
    function vk_api_fine_row(array $r, int $ownMemberId = 0): array
    {
        $status = vk_normalize_fine_status($r['status'] ?? 'pending');

        $name = trim((string) ($r['member_name'] ?? ''));
        if ($name === '') {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        }

        return [
            'fine_id'        => (int) $r['fine_id'],
            'member_id'      => (int) $r['customer_id'],
            'member_name'    => $name,
            'is_self'        => $ownMemberId > 0 && (int) $r['customer_id'] === $ownMemberId,
            'amount'         => (float) $r['amount'],
            'reason'         => trim((string) ($r['reason'] ?? '')) !== '' ? (string) $r['reason'] : null,
            'status'         => $status,
            'is_outstanding' => $status === 'pending',
            'meeting_id'     => isset($r['meeting_id']) && $r['meeting_id'] !== null
                ? (int) $r['meeting_id'] : null,
            'meeting_title'  => trim((string) ($r['meeting_title'] ?? '')) !== ''
                ? (string) $r['meeting_title'] : null,
            'created_at'     => !empty($r['created_at'])
                ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'updated_at'     => !empty($r['updated_at'])
                ? date(DATE_ATOM, strtotime((string) $r['updated_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_fine_actions')) {
    /**
     * What THIS caller may do to a fine in this status.
     *
     * Sent with every row so the app never re-derives the workflow and never
     * offers a button the server would refuse.
     */
    function vk_api_fine_actions(array $auth, string $status): array
    {
        $may = vk_api_fines_is_leader($auth);
        $status = vk_normalize_fine_status($status);

        return [
            'edit'  => $may,
            // Paying a paid fine or waiving a waived one is a no-op the app
            // should not offer.
            'pay'   => $may && $status !== 'paid',
            'waive' => $may && $status !== 'waived',
        ];
    }
}

if (!function_exists('vk_api_fine_amount')) {
    /**
     * Validate a submitted fine amount, returning it rounded.
     *
     * Thousands separators are stripped the way actions/save_fine.php strips
     * them: a treasurer typing "10,000" on a phone keypad is entering ten
     * thousand, and refusing it teaches them to distrust the form.
     */
    function vk_api_fine_amount($raw): float
    {
        $clean = str_replace([',', ' '], '', (string) $raw);

        if ($clean === '' || !is_numeric($clean)) {
            vk_api_error(422, 'invalid_amount', 'amount is required and must be a number.');
        }
        $amount = round((float) $clean, 2);
        if ($amount <= 0) {
            vk_api_error(422, 'invalid_amount', 'amount must be greater than zero.');
        }
        // decimal(15,2) — MySQL truncates a larger value rather than refusing it,
        // which would silently record a different figure from the one submitted.
        if ($amount > 9999999999999.99) {
            vk_api_error(422, 'invalid_amount', 'amount is too large.');
        }
        return $amount;
    }
}

if (!function_exists('vk_api_fine_reason')) {
    /**
     * Validate a submitted reason.
     *
     * Required, because a fine with no reason is a figure nobody can defend when
     * the member asks why — the same rule actions/save_fine.php enforces.
     */
    function vk_api_fine_reason($raw): string
    {
        $reason = trim((string) ($raw ?? ''));
        if ($reason === '') {
            vk_api_error(422, 'reason_required', 'Give a reason for the fine.');
        }
        return $reason;
    }
}

if (!function_exists('vk_api_fine_load')) {
    /**
     * One fine by id, with its member and meeting, or a 404.
     *
     * @return array the raw row
     */
    function vk_api_fine_load(PDO $pdo, int $fineId): array
    {
        if ($fineId <= 0) {
            vk_api_error(422, 'invalid_id', 'A fine id is required.');
        }
        $st = $pdo->prepare(
            "SELECT f.*,
                    TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name,
                    m.title AS meeting_title
               FROM fines f
               LEFT JOIN customers c ON c.customer_id = f.customer_id
               LEFT JOIN meetings  m ON m.id = f.meeting_id
              WHERE f.fine_id = ?"
        );
        $st->execute([$fineId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No fine was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_fine_totals')) {
    /**
     * Money owed / collected / forgiven across a filtered set.
     *
     * Computed in SQL over the whole filtered set rather than by summing the
     * page, so the header figures do not change as the member scrolls.
     *
     * @param string $whereSql already-built WHERE, or ''
     */
    function vk_api_fine_totals(PDO $pdo, string $whereSql, array $params): array
    {
        $st = $pdo->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN f.status = 'pending' THEN f.amount ELSE 0 END), 0) AS pending,
               COALESCE(SUM(CASE WHEN f.status = 'paid'    THEN f.amount ELSE 0 END), 0) AS paid,
               COALESCE(SUM(CASE WHEN f.status = 'waived'  THEN f.amount ELSE 0 END), 0) AS waived,
               COUNT(*) AS cnt
             FROM fines f
             LEFT JOIN customers c ON c.customer_id = f.customer_id
             {$whereSql}"
        );
        $st->execute($params);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            // The only figure that is money still owed.
            'outstanding' => (float) ($t['pending'] ?? 0),
            'paid'        => (float) ($t['paid'] ?? 0),
            'waived'      => (float) ($t['waived'] ?? 0),
            'count'       => (int) ($t['cnt'] ?? 0),
        ];
    }
}

if (!function_exists('vk_api_fine_filters')) {
    /**
     * The query filters the list endpoints accept, validated into [sql, params].
     *
     * @return array{0:string[],1:array}
     */
    function vk_api_fine_filters(array $q): array
    {
        $where  = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, vk_fine_statuses(), true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: '
                    . implode(', ', vk_fine_statuses()) . '.');
            }
            $where[]  = 'f.status = ?';
            $params[] = $status;
        }

        // Validated rather than passed through: an unparseable date would become
        // a silent full scan that ignores the filter the user asked for.
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            $raw = trim((string) ($q[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $d = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw) {
                vk_api_error(422, 'invalid_date', $key . ' must be a date in YYYY-MM-DD format.');
            }
            $where[]  = "DATE(f.created_at) {$op} ?";
            $params[] = $raw;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR f.reason LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }
}
