<?php
/**
 * GET  /api/v1/petty-cash — the group's petty-cash vouchers, paginated
 * POST /api/v1/petty-cash — record one (delegates to petty-cash_create.php)
 *
 * Mirrors app/constant/accounts/petty_cash.php and actions/fetch_petty_cash.php.
 *
 * Gated on `petty_cash` — a NEW permission key (see
 * database/add_petty_cash_permission.php), normalizing what the web checks
 * inconsistently across its own petty-cash files (see includes/api_petty_cash.php's
 * own note). actions/fetch_petty_cash.php had NO permission check at all before
 * this; that hole is closed alongside this endpoint, not copied forward.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_petty_cash.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/petty-cash_create.php';
    exit;
}

vk_api_require_permission($auth, 'view', 'petty_cash');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_petty_filters($_GET, 'v');

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$from = 'FROM petty_cash_vouchers v';

$st = $pdo->prepare("SELECT COUNT(*) {$from} {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(v.amount), 0) {$from} {$whereSql}");
$st->execute($params);
$filteredSum = (float) $st->fetchColumn();

$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT v.*, u.username AS prepared_by_name
      {$from}
      LEFT JOIN users u ON u.user_id = v.prepared_by
      {$whereSql}
     ORDER BY v.transaction_date DESC, v.id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $r) use ($auth): array {
    $row = vk_api_petty_row($r);
    $row['actions'] = vk_api_petty_actions($auth, $row['status']);
    return $row;
}, $rows);

vk_api_ok([
    'vouchers' => $data,
    'totals' => [
        'filtered_amount' => $filteredSum,
        'filtered_count'  => $total,
    ],
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($data)) < $total,
    ],
]);
