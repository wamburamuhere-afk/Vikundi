<?php
/**
 * GET  /api/v1/contributions   — the ledger, paginated and filtered
 * POST /api/v1/contributions   — record one (delegates to contributions_create.php)
 *
 * Mirrors app/bms/customer/manage_contributions.php.
 *
 * THE SCOPING RULE IS THE WHOLE ENDPOINT. A leader sees the group; anyone else
 * sees only their own rows, enforced by overwriting member_id rather than by
 * trusting the client to omit it. See includes/api_contributions.php.
 *
 * NO PAGE-PERMISSION GATE ON READ, deliberately. A member reading their own
 * savings needs no grant beyond being signed in — the same rule /dashboard
 * already follows for a member's own figures. The `manage_contributions` grant
 * is what widens the view to the whole group, and vk_api_contrib_scope() is
 * where that widening happens.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_contributions.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/contributions_create.php';
    exit;
}

$scope = vk_api_contrib_scope($auth, (int) ($_GET['member_id'] ?? 0));

// -----------------------------------------------------------------------------
// Filters
// -----------------------------------------------------------------------------
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

$where  = [];
$params = [];

if ($scope['member_id'] > 0) {
    $where[]  = 'co.member_id = ?';
    $params[] = $scope['member_id'];
}

$status = trim((string) ($_GET['status'] ?? ''));
if ($status !== '') {
    if (!in_array($status, vk_api_contrib_statuses(), true)) {
        vk_api_error(422, 'invalid_status', 'status must be one of: '
            . implode(', ', vk_api_contrib_statuses()) . '.');
    }
    $where[]  = 'co.status = ?';
    $params[] = $status;
}

$type = trim((string) ($_GET['type'] ?? ''));
if ($type !== '') {
    if (!in_array($type, vk_api_contrib_types(), true)) {
        vk_api_error(422, 'invalid_type', 'type must be one of: '
            . implode(', ', vk_api_contrib_types()) . '.');
    }
    $where[]  = 'co.contribution_type = ?';
    $params[] = $type;
}

// Dates are validated rather than passed through: an unparseable string would
// otherwise become a silent full-table scan that quietly ignores the filter.
foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
    $raw = trim((string) ($_GET[$key] ?? ''));
    if ($raw === '') {
        continue;
    }
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$d || $d->format('Y-m-d') !== $raw) {
        vk_api_error(422, 'invalid_date', $key . ' must be a date in YYYY-MM-DD format.');
    }
    $where[]  = "co.contribution_date {$op} ?";
    $params[] = $raw;
}

$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $where[] = '(c.customer_name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?
                 OR co.receipt_number LIKE ? OR co.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$from = 'FROM contributions co LEFT JOIN customers c ON c.customer_id = co.member_id';

$st = $pdo->prepare("SELECT COUNT(*) {$from} {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

// The filtered total, so the app can show "TZS 4,300,000 across 128 records"
// without paging the whole set to add it up.
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
    $row = vk_api_contrib_row($r);
    $row['actions'] = vk_api_contrib_actions($auth, $row['status']);
    return $row;
}, $rows);

vk_api_ok([
    'contributions' => $data,
    // Tells the app whether it is showing a ledger or a personal statement,
    // without it having to infer that from the role.
    'scope' => [
        'is_leader'     => $scope['is_leader'],
        'member_id'     => $scope['member_id'] > 0 ? $scope['member_id'] : null,
        'own_member_id' => $scope['own_member_id'] > 0 ? $scope['own_member_id'] : null,
    ],
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
