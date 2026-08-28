<?php
/**
 * GET  /api/v1/fines — the group's fines, paginated
 * POST /api/v1/fines — record one (delegates to fines_create.php)
 *
 * Mirrors app/bms/customer/manage_fines.php and api/get_fines.php.
 *
 * LEADERSHIP ONLY. A member's own fines are at /api/v1/my/fines, which also
 * carries the ?view=all toggle the group asked for — so this endpoint being
 * closed does not hide anything from members. What it protects is the
 * leadership screen's filters and totals, not the underlying disclosure.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_fines.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/fines_create.php';
    exit;
}

vk_api_fines_require_leader($auth, 'view the group\'s fines');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_fine_filters($_GET);

$memberId = (int) ($_GET['member_id'] ?? 0);
if ($memberId > 0) {
    $where[]  = 'f.customer_id = ?';
    $params[] = $memberId;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$from = 'FROM fines f LEFT JOIN customers c ON c.customer_id = f.customer_id';

$totals = vk_api_fine_totals($pdo, $whereSql, $params);
$total  = $totals['count'];
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT f.*,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name,
           m.title AS meeting_title
      {$from}
      LEFT JOIN meetings m ON m.id = f.meeting_id
      {$whereSql}
     ORDER BY f.created_at DESC, f.fine_id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$own = vk_api_member_id((int) $auth['user_id']);

$data = array_map(static function (array $r) use ($auth, $own): array {
    $row = vk_api_fine_row($r, $own);
    $row['actions'] = vk_api_fine_actions($auth, $row['status']);
    return $row;
}, $rows);

vk_api_ok([
    'fines'  => $data,
    'totals' => $totals,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($data)) < $total,
    ],
]);
