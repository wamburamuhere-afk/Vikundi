<?php
/**
 * GET /api/v1/transactions — the group's money by the date it arrived.
 *
 * Mirrors app/bms/customer/transactions.php and api/get_transactions.php.
 *
 * LEADERSHIP ONLY, as a hard 403 rather than a narrowing to the caller's own
 * rows. A member's own receipts are a different document with a different shape
 * and they live at /api/v1/my/transactions.
 *
 * WHAT THIS ADDS OVER /contributions, which reads the same table:
 *
 *   1. The M-Koba statement columns (mkoba.sno, trans_id, member_id_str,
 *      source, destination, trans_type). Without them a treasurer cannot tie
 *      the books to the statement, which is the entire purpose of the screen.
 *   2. An `account` filter — reconciling means looking at one channel at a time.
 *
 * The row is otherwise vk_api_contrib_row(), so amount, status, is_opening and
 * counts_toward_savings cannot mean different things on the two endpoints.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_transactions.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_txn_require_leader($auth);

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_txn_filters($_GET, 'co');

// Leadership may narrow to one member; unlike /contributions this is a genuine
// filter, not a scoping overwrite, because the caller can already see everyone.
$memberId = (int) ($_GET['member_id'] ?? 0);
if ($memberId > 0) {
    $where[]  = 'co.member_id = ?';
    $params[] = $memberId;
}

$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    // Receipt and M-Koba transaction id are what someone holding a paper
    // statement actually types in, so both are searchable alongside the name.
    $where[] = "(c.customer_name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?
                 OR co.receipt_number LIKE ? OR co.mkoba_trans_id LIKE ?
                 OR co.mkoba_sno LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$from = 'FROM contributions co LEFT JOIN customers c ON c.customer_id = co.member_id';

$st = $pdo->prepare("SELECT COUNT(*) {$from} {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

// The filtered total, so the app can head the screen with "TZS 4,300,000 across
// 128 records" without paging the whole set to add it up.
$st = $pdo->prepare("SELECT COALESCE(SUM(co.amount), 0) {$from} {$whereSql}");
$st->execute($params);
$filteredSum = (float) $st->fetchColumn();

$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT co.*, c.customer_name, c.first_name, c.last_name
      {$from}
      {$whereSql}
     ORDER BY co.contribution_date DESC, co.contribution_id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $r) use ($auth): array {
    $row = vk_api_txn_row($r);
    $row['actions'] = vk_api_contrib_actions($auth, $row['status']);
    return $row;
}, $rows);

vk_api_ok([
    'transactions' => $data,
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
