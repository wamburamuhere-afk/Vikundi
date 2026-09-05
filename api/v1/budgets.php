<?php
/**
 * GET  /api/v1/budgets — the group's budgets, paginated
 * POST /api/v1/budgets — record one (delegates to budgets_create.php)
 *
 * Mirrors app/constant/accounts/budget.php and api/account/get_budget.php's
 * list shape. LEADERSHIP ONLY (`budget`) — a new permission key, registered
 * from scratch (see includes/api_budgets.php's own note); unlike Expenses/
 * Petty Cash there is no live Member grant to mirror here.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_budgets.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/budgets_create.php';
    exit;
}

vk_api_require_permission($auth, 'view', 'budget');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_budgets_filters($_GET, 'b');

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM budgets b {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(b.allocated_amount), 0) FROM budgets b {$whereSql}");
$st->execute($params);
$filteredSum = (float) $st->fetchColumn();

$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT b.* FROM budgets b
      {$whereSql}
     ORDER BY b.budget_year DESC, b.budget_month DESC, b.budget_id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $r) use ($auth): array {
    // No items on the list — that would be N+1 queries for a page of budgets.
    // Fetch GET /budgets/{id} for the line-item breakdown.
    $row = vk_api_budgets_row($r);
    $row['actions'] = vk_api_budgets_actions($auth, $row['status']);
    return $row;
}, $rows);

vk_api_ok([
    'budgets' => $data,
    'totals' => [
        'filtered_allocated' => $filteredSum,
        'filtered_count'     => $total,
    ],
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($data)) < $total,
    ],
]);
