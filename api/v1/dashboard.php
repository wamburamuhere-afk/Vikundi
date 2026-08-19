<?php
/**
 * GET /api/v1/dashboard
 *
 * The role-aware home screen, mirroring app/dashboard.php.
 *
 * Leadership (Admin, Chairperson, Secretary, Treasurer) get the group's
 * position and the pending-approval queue. An ordinary member gets their own
 * standing and nothing about anyone else's money.
 *
 * EVERY FIGURE HERE IS DELEGATED, never recomputed. The savings total comes from
 * cs_group_savings_total(), arrears from cs_member_arrears(), the balance from
 * getGroupFundBalance(). A dashboard that says "you owe 60,000" above a
 * statement showing a different number is how a member stops trusting both, and
 * a mobile app that disagrees with the web is the same failure with an extra
 * device in it. The SQL below is copied from app/dashboard.php deliberately so
 * the two produce identical numbers.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../includes/contribution_standing.php';
require_once __DIR__ . '/../../includes/finance.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();

$userId       = $auth['user_id'];
$roleId       = $auth['role_id'];
$roleName     = (string) ($auth['user']['user_role'] ?? '');
$isLeadership = vk_role_is_leadership($roleId, $roleName);
$isAdmin      = vk_role_is_admin($roleId, $roleName);

// -----------------------------------------------------------------------------
// The caller's own position. Always present — a Treasurer is also a saving
// member, and the app shows them their own statement alongside the group's.
// -----------------------------------------------------------------------------
$memberId = vk_api_member_id($userId);

$myTotal = 0.0;
$myPending = 0;
$myArrears = ['behind' => false, 'amount' => 0.0, 'months' => 0, 'oldest' => null];

if ($memberId > 0) {
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(c.amount),0)
           FROM contributions c
           JOIN customers cu ON c.member_id = cu.customer_id
          WHERE cu.user_id = ? AND c.status IN ('confirmed', 'approved', '')"
    );
    $st->execute([$userId]);
    $myTotal = (float) $st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(*)
           FROM contributions c
           JOIN customers cust ON c.member_id = cust.customer_id
          WHERE cust.user_id = ? AND c.status = 'pending'"
    );
    $st->execute([$userId]);
    $myPending = (int) $st->fetchColumn();

    $myArrears = cs_member_arrears($pdo, $memberId);
}

$payload = [
    'role' => [
        'role_id'       => $roleId,
        'role'          => $roleName,
        'is_admin'      => $isAdmin,
        'is_leadership' => $isLeadership,
    ],
    'me' => [
        'member_id'           => $memberId ?: null,
        'total_contributions' => round($myTotal, 2),
        'pending_contributions' => $myPending,
        'arrears' => [
            'behind' => (bool) $myArrears['behind'],
            'amount' => round((float) $myArrears['amount'], 2),
            'months' => (int) $myArrears['months'],
            'oldest' => $myArrears['oldest'],
        ],
    ],
];

// -----------------------------------------------------------------------------
// Group-wide figures. Withheld from a plain member entirely — not merely hidden
// in the UI. The web app hides these behind a template condition; an API that
// returned them anyway would leak the group's cash position to any member who
// read the JSON.
// -----------------------------------------------------------------------------
if ($isLeadership) {
    $mrow = $pdo->query("
        SELECT
          COALESCE(SUM(status <> 'deleted' AND user_role <> 'Admin'), 0) AS total,
          COALESCE(SUM(status =  'active'  AND user_role <> 'Admin'), 0) AS active,
          COALESCE(SUM(status =  'pending'), 0)                          AS pending
        FROM users
    ")->fetch(PDO::FETCH_ASSOC);

    $monthContributions = (float) $pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM contributions
          WHERE status IN ('confirmed', 'approved', '')
            AND MONTH(contribution_date) = MONTH(NOW())
            AND YEAR(contribution_date)  = YEAR(NOW())"
    )->fetchColumn();

    $pendingContributions = (int) $pdo->query(
        "SELECT COUNT(*) FROM contributions c
           JOIN customers cust ON c.member_id = cust.customer_id
          WHERE c.status = 'pending' AND cust.status = 'active'"
    )->fetchColumn();

    $pendingFines = (float) $pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'pending'"
    )->fetchColumn();

    // One pass per expense table gets both the pending count and the authorised
    // total, exactly as the web dashboard does.
    $de = $pdo->query("SELECT
          COALESCE(SUM(status = 'pending'), 0) AS pending_ct,
          COALESCE(SUM(CASE WHEN status IN ('approved','paid') THEN amount ELSE 0 END), 0) AS approved_sum
        FROM death_expenses")->fetch(PDO::FETCH_ASSOC);

    $ge = $pdo->query("SELECT
          COALESCE(SUM(status = 'pending'), 0) AS pending_ct,
          COALESCE(SUM(CASE WHEN status IN ('approved','paid') THEN amount ELSE 0 END), 0) AS approved_sum
        FROM general_expenses")->fetch(PDO::FETCH_ASSOC);

    $pendingBudgets = (int) $pdo->query(
        "SELECT COUNT(*) FROM budgets WHERE status = 'pending'"
    )->fetchColumn();

    $deathTotal   = (float) $de['approved_sum'];
    $generalTotal = (float) $ge['approved_sum'];

    $payload['members'] = [
        'total'   => (int) $mrow['total'],
        'active'  => (int) $mrow['active'],
        'pending' => (int) $mrow['pending'],
    ];

    $payload['contributions'] = [
        'total'         => round(cs_group_savings_total($pdo), 2),
        'this_month'    => round($monthContributions, 2),
        'pending_count' => $pendingContributions,
    ];

    $payload['expenses'] = [
        'death'             => round($deathTotal, 2),
        'general'           => round($generalTotal, 2),
        'total'             => round($deathTotal + $generalTotal, 2),
        'approved_not_paid' => round(approvedNotYetPaidExpenses($pdo), 2),
    ];

    $payload['balance'] = [
        // Cash basis: money in minus money that actually left the account.
        'net' => round(getGroupFundBalance($pdo), 2),
    ];

    $payload['fines'] = ['pending_total' => round($pendingFines, 2)];

    $pending = [
        'members'          => (int) $mrow['pending'],
        'contributions'    => $pendingContributions,
        'death_expenses'   => (int) $de['pending_ct'],
        'general_expenses' => (int) $ge['pending_ct'],
        'budgets'          => $pendingBudgets,
    ];
    $pending['total'] = array_sum($pending);
    $payload['pending'] = $pending;
} else {
    // A member's only "pending" figure is their own, matching the web app's
    // alert strip.
    $payload['pending'] = ['contributions' => $myPending, 'total' => $myPending];
}

// -----------------------------------------------------------------------------
// Six-month trend. Two grouped queries fill pre-seeded buckets, same as the web.
// -----------------------------------------------------------------------------
if ($isLeadership) {
    $keys = [];
    $labels = [];
    $bucketC = [];
    $bucketE = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts  = strtotime("-{$i} months");
        $key = date('Y-m', $ts);
        $keys[]   = $key;
        $labels[] = date('M Y', $ts);
        $bucketC[$key] = 0.0;
        $bucketE[$key] = 0.0;
    }
    $since = $keys[0] . '-01';

    $st = $pdo->prepare(
        "SELECT DATE_FORMAT(contribution_date, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
           FROM contributions
          WHERE status IN ('confirmed', 'approved', '') AND contribution_date >= ?
          GROUP BY ym"
    );
    $st->execute([$since]);
    foreach ($st as $r) {
        if (isset($bucketC[$r['ym']])) {
            $bucketC[$r['ym']] = (float) $r['total'];
        }
    }

    $st = $pdo->prepare(
        "SELECT ym, COALESCE(SUM(amount),0) AS total FROM (
            SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, amount FROM death_expenses
             WHERE status IN ('approved','paid') AND expense_date >= ?
            UNION ALL
            SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, amount FROM general_expenses
             WHERE status IN ('approved','paid') AND expense_date >= ?
        ) x GROUP BY ym"
    );
    $st->execute([$since, $since]);
    foreach ($st as $r) {
        if (isset($bucketE[$r['ym']])) {
            $bucketE[$r['ym']] = (float) $r['total'];
        }
    }

    $payload['trend'] = [
        'labels'        => $labels,
        'contributions' => array_map(static fn ($k) => round($bucketC[$k], 2), $keys),
        'expenses'      => array_map(static fn ($k) => round($bucketE[$k], 2), $keys),
    ];
}

// -----------------------------------------------------------------------------
// Recent activity. The web app shows this to admin/chairman/mwenyekiti only, so
// the API restricts it to full admins rather than all leadership — a Secretary
// does not see the audit trail on the web and must not gain it by switching
// device.
// -----------------------------------------------------------------------------
if ($isAdmin) {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.module, al.description, al.created_at,
                TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS full_name,
                u.user_role AS role
           FROM activity_logs al
           JOIN users u ON al.user_id = u.user_id
          ORDER BY al.created_at DESC
          LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $payload['recent_activity'] = array_map(static function (array $r): array {
        return [
            'id'          => (int) $r['id'],
            'action'      => (string) $r['action'],
            'module'      => (string) ($r['module'] ?? ''),
            'description' => (string) ($r['description'] ?? ''),
            'user'        => ($r['full_name'] !== '' ? $r['full_name'] : (string) $r['role']),
            'role'        => (string) ($r['role'] ?? ''),
            // ISO-8601 so the client formats "2 hours ago" in the user's locale
            // rather than the server baking a Swahili or English string in.
            'created_at'  => date(DATE_ATOM, strtotime((string) $r['created_at'])),
        ];
    }, $rows);
}

$payload['currency'] = 'TZS';
$payload['generated_at'] = date(DATE_ATOM);

vk_api_ok($payload);
