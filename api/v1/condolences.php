<?php
/**
 * GET  /api/v1/condolences — the group's condolence cases, paginated
 * POST /api/v1/condolences — record one (delegates to condolences_create.php)
 *
 * Mirrors app/constant/accounts/death_expenses.php and api/get_death_expenses.php.
 *
 * LEADERSHIP ONLY. Unlike /contributions, there is no scoped "my own" branch
 * here: no web screen ever showed a member their own condolence cases, so
 * there is no existing behaviour to preserve by scoping. A member's own cases
 * are GET /api/v1/my/condolences.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_death_expenses.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/condolences_create.php';
    exit;
}

vk_api_death_require_leader($auth);

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_death_filters($_GET, 'de');

// Leadership may narrow to one member; a genuine filter here, not a scoping
// overwrite, because the caller can already see everyone.
$memberId = (int) ($_GET['member_id'] ?? 0);
if ($memberId > 0) {
    $where[]  = 'de.member_id = ?';
    $params[] = $memberId;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$from = 'FROM death_expenses de LEFT JOIN customers c ON c.customer_id = de.member_id';

$st = $pdo->prepare("SELECT COUNT(*) {$from} {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(de.amount), 0) {$from} {$whereSql}");
$st->execute($params);
$filteredSum = (float) $st->fetchColumn();

$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT de.*, TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name
      {$from}
      {$whereSql}
     ORDER BY de.created_at DESC, de.id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$own = vk_api_member_id((int) $auth['user_id']);

$data = array_map(static function (array $r) use ($auth, $own): array {
    $row = vk_api_death_row($r, $own);
    $row['actions'] = vk_api_death_actions($auth, $row['status']);
    return $row;
}, $rows);

vk_api_ok([
    'condolences' => $data,
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
