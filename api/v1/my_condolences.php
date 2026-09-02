<?php
/**
 * GET /api/v1/my/condolences — the signed-in member's own condolence cases.
 *
 * This is new: no web screen ever showed a member their own condolence
 * history — death_expenses.view was granted to the Member role with no
 * screen that used it, which is exactly the hole
 * includes/death_expense_access.php closed. This endpoint is that grant's
 * first legitimate use.
 *
 * The member comes from the token; there is no member_id parameter on this
 * endpoint, so there is nothing to overwrite or tamper with. Leadership
 * asking for someone else's cases uses GET /condolences?member_id=, not this.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_death_expenses.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
$own  = vk_api_member_id((int) $auth['user_id']);

if ($own <= 0) {
    vk_api_error(
        403,
        'no_member_record',
        'This account has no member record, so it has no condolence cases of its own.'
    );
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_death_filters($_GET, 'de');
$where[]  = 'de.member_id = ?';
$params[] = $own;

$whereSql = 'WHERE ' . implode(' AND ', $where);
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
