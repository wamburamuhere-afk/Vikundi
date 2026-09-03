<?php
/**
 * GET /api/v1/ledger — the group financial ledger: every member's opening
 * balance, entrance/monthly contributions, aid, AGM payments and standing for
 * a period, plus the group's available fund balance.
 *
 * Mirrors app/bms/customer/financial_ledger.php exactly (see
 * includes/api_financial_ledger.php) and adds getGroupFundBalance() — a
 * figure that web report does not show but this module's own line item in
 * todo.md promised ("financial_ledger.php, group fund balance").
 *
 * LEADERSHIP ONLY (`vicoba_reports`) — the same gate the web report uses.
 * Query params: start_date, end_date (YYYY-MM-DD, default the current
 * calendar year), member_id (narrow to one member), search (name/M-Koba name),
 * page, per_page.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_financial_ledger.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_ledger_require_leader($auth);

[$startDate, $endDate] = vk_api_ledger_period($_GET);

$ledger = vk_api_ledger_build($pdo, $startDate, $endDate);
$rows   = $ledger['rows'];

// Leadership may narrow to one member — a genuine filter, not a scoping
// overwrite, because the caller can already see everyone.
$memberId = (int) ($_GET['member_id'] ?? 0);
if ($memberId > 0) {
    $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['member_id'] === $memberId));
}

$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $needle = mb_strtolower($search);
    $rows = array_values(array_filter($rows, static function (array $r) use ($needle): bool {
        return str_contains(mb_strtolower($r['member_name']), $needle)
            || str_contains(mb_strtolower((string) ($r['mkoba_name'] ?? '')), $needle);
    }));
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));
$total   = count($rows);
$offset  = ($page - 1) * $perPage;
$pageRows = array_slice($rows, $offset, $perPage);

vk_api_ok([
    'period' => [
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'months'     => $ledger['months'],
    ],
    'fund_balance'          => getGroupFundBalance($pdo),
    'approved_not_yet_paid' => approvedNotYetPaidExpenses($pdo),
    'totals'                => $ledger['totals'],
    'members'               => $pageRows,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($pageRows)) < $total,
    ],
]);
