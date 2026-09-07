<?php
/**
 * includes/api_reports.php — Module 15: Reports & Statements.
 *
 * Every figure comes from the SAME shared modules the web reports already use —
 * includes/contribution_standing.php for every money/grid computation, plus
 * includes/finance.php for the one group-wide fund figure that isn't specific
 * to contributions. None of this arithmetic is redone here; this file only
 * shapes the JSON envelope around it, exactly the established pattern from
 * api/v1/contributions_standing.php.
 *
 * TWO PERMISSION SHAPES, mirroring the web exactly, file by file — verified
 * individually rather than assumed, given the Documents/Voting incidents:
 *
 *   member-statement / member-transactions — NO permission-key gate at all on
 *   the web (app/constant/reports/member_statement.php,
 *   member_transactions.php); only session-login + an inline ownership check.
 *   `?id` is honoured ONLY for a caller who is isAdmin() OR holds `create` on
 *   `manage_contributions` — everyone else is silently forced back to their
 *   OWN customer_id, resolved from `customers.user_id`, never trusted from the
 *   request. This module's endpoints call vk_api_require_auth() only, no
 *   vk_api_require_permission() — matching the web precisely, not inventing a
 *   gate the web has never had.
 *
 *   group-statement / reports/vicoba / reports/customer-analysis — all four
 *   gate on `canView('vicoba_reports')` on the web (includes/group_statement.php,
 *   vicoba_reports.php, customer_analysis.php). todo.md's plan labels the
 *   group-statement endpoints "leadership only," but the web's own code
 *   comment (includes/group_statement.php) states this is deliberate existing
 *   policy: "Group-wide figures are visible to members in this product...
 *   this page does not widen that, and must not narrow it either." Mirrored
 *   as-is — `vicoba_reports` view, which today also reaches Member — rather
 *   than inventing a stricter gate the web itself does not have. If this
 *   session's local DB grants for `vicoba_reports` do not match production's,
 *   that is re-verified live after deploy like every permission claim.
 *
 * FIXED, not ported: customer_analysis.php's "is this row a member, not an
 * admin" filter is the string `users.user_role != 'Admin'` — a legacy,
 * hand-typed column that drifts from the real `role_id` (confirmed live: a
 * genuine role_id=1 Admin account whose stale user_role field reads 'Member'
 * is miscounted as an ordinary member on the web page today). This module's
 * customer-analysis endpoint filters on `role_id NOT IN (1,2,12)` instead —
 * the same set isAdmin() itself bypasses — and the web file is fixed to match
 * in the same change.
 */
require_once __DIR__ . '/api_auth.php';                 // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/contribution_standing.php';     // cs_* — every money/grid computation
require_once __DIR__ . '/finance.php';                   // getGroupFundBalance()

if (!function_exists('vk_api_reports_as_of')) {
    /**
     * "as_of=YYYY-MM" -> the 1st of that month, or today when absent/malformed.
     * Identical semantics to the three copies of this parsing this module's web
     * pages carry (member_statement.php, member_transactions.php,
     * group_statement.php) — collapsed here into one function since this is a
     * new module's own shared file, not an edit to any of those three.
     */
    function vk_api_reports_as_of($raw): DateTime
    {
        $raw = (string) ($raw ?? '');
        $asOf = preg_match('/^\d{4}-\d{2}$/', $raw)
            ? DateTime::createFromFormat('Y-m-d', $raw . '-01')
            : new DateTime('today');
        return $asOf ?: new DateTime('today');
    }
}

if (!function_exists('vk_api_reports_is_leader')) {
    /**
     * The EXACT test member_statement.php / member_transactions.php use —
     * canCreate('manage_contributions'), not canEdit(). A different leader test
     * (canEdit) is used elsewhere in this codebase (includes/contribution_access.php)
     * for a different feature; replicating the specific page's own test rather
     * than assuming one canonical "is leader" rule is deliberate here.
     */
    function vk_api_reports_is_leader(array $auth): bool
    {
        return vk_api_is_admin((int) $auth['role_id']) || vk_api_can($auth, 'create', 'manage_contributions');
    }
}

if (!function_exists('vk_api_reports_resolve_member_id')) {
    /**
     * Mirrors member_statement.php / member_transactions.php exactly: a
     * requested id is honoured only for a leader; anyone else (or a leader who
     * passed no id) is resolved to their OWN customer_id. Returns 0 when the
     * caller has no member record at all (an Admin login, typically).
     */
    function vk_api_reports_resolve_member_id(PDO $pdo, array $auth, int $requestedId): int
    {
        if (vk_api_reports_is_leader($auth) && $requestedId > 0) {
            return $requestedId;
        }
        return vk_api_member_id((int) $auth['user_id']);
    }
}

if (!function_exists('vk_api_reports_member_details')) {
    /** The "Member Details" panel — mirrors member_statement.php's own fields. */
    function vk_api_reports_member_details(array $member): array
    {
        $name = trim(implode(' ', array_filter([
            $member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? '',
        ])));
        $residence = trim(implode(', ', array_filter([
            $member['ward'] ?? '', $member['district'] ?? '', $member['state'] ?? '',
        ])));
        $spouseActive = (($member['marital_status'] ?? '') === 'Married' && empty($member['spouse_deceased'])) ? 1 : 0;
        $children = json_decode($member['children_data'] ?? '[]', true);
        $activeChildren = 0;
        if (is_array($children)) {
            foreach ($children as $child) {
                if (empty($child['is_deceased'] ?? false)) {
                    $activeChildren++;
                }
            }
        }

        return [
            'id'                  => (int) $member['customer_id'],
            'name'                => $name,
            'registration_number' => trim((string) ($member['registration_number'] ?? '')) ?: null,
            'nida_number'         => trim((string) ($member['nida_number'] ?? '')) ?: null,
            'phone'               => trim((string) ($member['phone'] ?? $member['mobile'] ?? '')) ?: null,
            'dob'                 => !empty($member['dob']) ? $member['dob'] : null,
            'joined_at'           => !empty($member['created_at']) ? $member['created_at'] : null,
            'residence'           => $residence ?: null,
            'dependants'          => [
                'total'    => $spouseActive + $activeChildren,
                'children' => $activeChildren,
                'spouse'   => $spouseActive,
            ],
        ];
    }
}

if (!function_exists('vk_api_reports_condolences')) {
    /** Disbursed-only condolences for one member — matches both statements' filter exactly. */
    function vk_api_reports_condolences(PDO $pdo, int $memberId): array
    {
        $st = $pdo->prepare("SELECT * FROM death_expenses WHERE member_id = ? AND status IN ('approved','paid') ORDER BY expense_date DESC");
        $st->execute([$memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vk_api_reports_condolence_row')) {
    function vk_api_reports_condolence_row(array $r): array
    {
        return [
            'date'         => $r['expense_date'],
            'deceased'     => (string) $r['deceased_name'],
            'relationship' => (string) ($r['deceased_relationship'] ?: $r['deceased_type']),
            'amount'       => (float) $r['amount'],
        ];
    }
}

if (!function_exists('vk_api_group_statement')) {
    /**
     * Shared builder for both group-statement types, mirroring
     * includes/group_statement.php exactly — one pass over cs_group_schedules()
     * (+ cs_group_receipts() for transactions), a grid per member, and the
     * merged group grid/summary. Unlike the web (which switches HTML between a
     * `view=combined`/`view=members` query param), this always returns BOTH the
     * merged group figures and the full per-member table in one response — a
     * JSON client has no reason to pay for two requests when computing one
     * already produces both.
     */
    function vk_api_group_statement(PDO $pdo, string $kind, DateTime $asOf): array
    {
        $isTx = $kind === 'transactions';

        $settings = $pdo->query('SELECT setting_key, setting_value FROM group_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $monthly  = (float) ($settings['monthly_contribution'] ?? 0);

        $schedules = cs_group_schedules($pdo, $asOf);
        $receipts  = $isTx ? cs_group_receipts($pdo) : [];

        $perMember = [];
        $grids     = [];
        foreach ($schedules as $cid => $row) {
            $grid = $isTx
                ? cs_transaction_grid($receipts[$cid] ?? [], $monthly, $row['schedule']['anchor_ym'], $asOf)
                : cs_calendar_grid($row['schedule'], $asOf);

            $summary = cs_year_summary($grid);
            $grids[] = $grid;

            $perMember[] = [
                'id'       => (int) $cid,
                'name'     => (string) $row['name'],
                'joined_at' => $row['joined'] ?: null,
                'target'   => $summary['total']['target'],
                'actual'   => $summary['total']['actual'],
                'variance' => $summary['total']['variance'],
                'paid'     => $summary['total']['paid'],
                'status'   => $summary['total']['target'] <= 0
                    ? 'no_target'
                    : ($summary['total']['variance'] < 0 ? 'behind' : 'up_to_date'),
            ];
        }

        $groupGrid    = cs_merge_grids($grids, $kind);
        $groupSummary = cs_year_summary($groupGrid);
        $behindCount  = count(array_filter($perMember, static fn(array $m): bool => $m['variance'] < 0));

        return [
            'as_of'         => $asOf->format('Y-m'),
            'monthly_target' => $monthly,
            'member_count'  => count($perMember),
            'behind_count'  => $behindCount,
            'group'         => [
                'grid'    => $groupGrid,
                'summary' => $groupSummary,
            ],
            'members'       => $perMember,
        ];
    }
}

if (!function_exists('vk_api_reports_vicoba')) {
    /**
     * Mirrors vicoba_reports.php's data, EXCEPT the fund-balance figure: that
     * page computes "available fund" as total_savings - total_expenses inline,
     * independent of includes/finance.php's getGroupFundBalance() (which also
     * folds in fines-in and petty-cash/payouts-out on a strict cash basis, and
     * is what the Dashboard and Financial Ledger already show). Reusing the
     * canonical helper here means this figure agrees with every other module
     * that already reports it; reimplementing the page's own narrower formula
     * would introduce a THIRD, different "available fund" number into the
     * mobile app. A deliberate deviation from a straight port, not an oversight.
     */
    function vk_api_reports_vicoba(PDO $pdo): array
    {
        $standing = cs_group_standing($pdo);

        $names = $pdo->query("
            SELECT customer_id,
                   COALESCE(CONCAT(first_name,' ',last_name), first_name, last_name) AS member_name,
                   phone
              FROM customers WHERE status <> 'deleted'
        ")->fetchAll(PDO::FETCH_UNIQUE);

        $savings = [];
        foreach ($standing as $cid => $st) {
            $savings[] = [
                'id'            => (int) $cid,
                'name'          => $names[$cid]['member_name'] ?? null,
                'phone'         => $names[$cid]['phone'] ?? null,
                'total_savings' => (float) $st['total'],
            ];
        }
        usort($savings, static fn(array $a, array $b): int => $b['total_savings'] <=> $a['total_savings']);

        $expenses = $pdo->query("
            (SELECT 'general' AS type, e.id AS id, e.expense_date AS date, e.amount, e.description
               FROM general_expenses e WHERE e.status IN ('approved','paid'))
            UNION ALL
            (SELECT 'death' AS type, d.id AS id, d.expense_date AS date, d.amount, d.description
               FROM death_expenses d WHERE d.status IN ('approved','paid'))
            ORDER BY date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $totalSavings  = array_sum(array_column($savings, 'total_savings'));
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $activeMembers = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'active'")->fetchColumn();

        return [
            'summary' => [
                'total_savings'   => $totalSavings,
                'total_expenses'  => $totalExpenses,
                'available_fund'  => getGroupFundBalance($pdo),
                'members_total'   => count($savings),
                'active_members'  => $activeMembers,
            ],
            'top_savers' => array_slice($savings, 0, 10),
            'expenses'   => array_map(static fn(array $e): array => [
                'type'        => (string) $e['type'],
                'id'          => (int) $e['id'],
                'date'        => $e['date'],
                'amount'      => (float) $e['amount'],
                'description' => trim((string) ($e['description'] ?? '')) ?: null,
            ], $expenses),
        ];
    }
}

if (!function_exists('vk_api_reports_customer_analysis')) {
    /**
     * Mirrors customer_analysis.php's aggregates, with one fix: "is this row a
     * member" is role_id NOT IN (1,2,12) — the same set vk_api_is_admin()/
     * isAdmin() already treat as full-access — not the legacy, hand-typed
     * `user_role != 'Admin'` string the web page compares against, which
     * miscounts any account whose user_role field was never kept in sync with
     * its real role_id (confirmed live on this session's own research pass).
     */
    function vk_api_reports_customer_analysis(PDO $pdo): array
    {
        $memberWhere = "u.role_id IS NULL OR u.role_id NOT IN (1,2,12)";

        $totalMembers  = (int) $pdo->query("SELECT COUNT(*) FROM users u WHERE ({$memberWhere}) AND u.status NOT IN ('deleted','rejected')")->fetchColumn();
        $activeMembers = (int) $pdo->query("SELECT COUNT(*) FROM users u WHERE ({$memberWhere}) AND u.status = 'active'")->fetchColumn();
        $deceasedCount = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE is_deceased = 1")->fetchColumn();
        $newLast30     = (int) $pdo->query("SELECT COUNT(*) FROM users u WHERE ({$memberWhere}) AND u.status NOT IN ('deleted','rejected') AND u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

        $regions = $pdo->query('SELECT state AS region, COUNT(*) AS count FROM customers GROUP BY state ORDER BY count DESC LIMIT 8')
            ->fetchAll(PDO::FETCH_ASSOC);
        $regionTotal = array_sum(array_column($regions, 'count'));

        $growth = $pdo->query("
            SELECT month, count FROM (
                SELECT DATE_FORMAT(u.created_at, '%Y-%m') AS month, COUNT(*) AS count
                  FROM users u
                 WHERE ({$memberWhere}) AND u.status NOT IN ('deleted','rejected')
                 GROUP BY month
                 ORDER BY month DESC
                 LIMIT 6
            ) t
            ORDER BY month ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $latest = $pdo->query("
            SELECT u.first_name, u.last_name, u.created_at, u.status, c.is_deceased
              FROM users u
              LEFT JOIN customers c ON u.user_id = c.user_id
             WHERE ({$memberWhere}) AND u.status <> 'deleted'
             ORDER BY u.created_at DESC
             LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'stats' => [
                'total_members'  => $totalMembers,
                'active_members' => $activeMembers,
                'deceased_count' => $deceasedCount,
                'new_last_30'    => $newLast30,
            ],
            'regions' => array_map(static function (array $r) use ($regionTotal): array {
                $count = (int) $r['count'];
                return [
                    'region'  => ($r['region'] !== null && $r['region'] !== '') ? $r['region'] : null,
                    'count'   => $count,
                    'percent' => $regionTotal > 0 ? round($count / $regionTotal * 100, 1) : 0.0,
                ];
            }, $regions),
            'growth_last_6_months' => array_map(static fn(array $g): array => [
                'month' => (string) $g['month'],
                'count' => (int) $g['count'],
            ], $growth),
            'latest_members' => array_map(static function (array $m): array {
                return [
                    'name'        => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
                    'joined_at'   => $m['created_at'],
                    'status'      => (string) $m['status'],
                    'is_deceased' => (bool) ($m['is_deceased'] ?? false),
                ];
            }, $latest),
        ];
    }
}
