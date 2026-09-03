<?php
/**
 * GET  /api/v1/expenses — the group's general expenses, paginated
 * POST /api/v1/expenses — record one (delegates to expenses_create.php)
 *
 * Mirrors app/constant/accounts/general_expenses.php and
 * api/get_general_expenses.php.
 *
 * Gated on `expenses` (view for the list). Verified live: Member already
 * holds this grant on demo/production today, same as the already-audited web
 * API (api/get_general_expenses.php, hardened under SEC-003/004/005) — see
 * includes/api_expenses.php's own note. There is no member-scoped variant of
 * this endpoint: general_expenses has never had a "my own expenses" screen.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_expenses.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/expenses_create.php';
    exit;
}

vk_api_require_permission($auth, 'view', 'expenses');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_expenses_filters($_GET, 'ge');

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$from = 'FROM general_expenses ge LEFT JOIN customers c ON c.customer_id = ge.member_id';

$st = $pdo->prepare("SELECT COUNT(*) {$from} {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(ge.amount), 0) {$from} {$whereSql}");
$st->execute($params);
$filteredSum = (float) $st->fetchColumn();

$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT ge.*, TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name
      {$from}
      {$whereSql}
     ORDER BY ge.created_at DESC, ge.id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $r) use ($auth): array {
    $row = vk_api_expenses_row($r);
    $row['actions'] = vk_api_expenses_actions($auth, $row['status']);
    return $row;
}, $rows);

vk_api_ok([
    'expenses' => $data,
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
