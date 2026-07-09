<?php
// api/get_transactions.php — server-side (paginated) data for the Transactions
// DataTable. The contributions table grows without bound, so paging/filtering/
// sorting all happen in the database; the browser only ever holds one page.
//
// Protocol: DataTables server-side (draw / start / length + filters). Counts and
// the page query are bounded by the date window so they stay cheap at scale, and
// they lean on the indexes from database/add_contributions_indexes.php.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3: must be logged in
require_once __DIR__ . '/../core/permissions.php';
global $pdo;

header('Content-Type: application/json');

// Group-wide financial data — leadership only.
if (!isAdmin() && !canView('manage_contributions')) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized.']);
    exit;
}

$draw   = (int) ($_GET['draw'] ?? 1);
$start  = max(0, (int) ($_GET['start'] ?? 0));
$length = (int) ($_GET['length'] ?? 25);
if ($length <= 0 || $length > 200) { $length = 25; } // clamp page size

// --- filters ------------------------------------------------------------------
$validYmd = function ($d) {
    $d = trim((string) $d);
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($d !== '' && $dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$status    = in_array($_GET['status'] ?? '', ['pending', 'reviewed', 'approved', 'cancelled'], true) ? $_GET['status'] : '';
$type      = in_array($_GET['type'] ?? '', ['monthly', 'entrance', 'agm', 'fine', 'other'], true) ? $_GET['type'] : '';
$account   = in_array($_GET['account'] ?? '', ['M-Koba', 'Bank', 'Cash', 'Mobile Money'], true) ? $_GET['account'] : '';
$member_id = ctype_digit((string) ($_GET['member_id'] ?? '')) ? (int) $_GET['member_id'] : 0;
$from      = $validYmd($_GET['date_from'] ?? '');
$to        = $validYmd($_GET['date_to'] ?? '');
$search    = trim((string) ($_GET['search']['value'] ?? ($_GET['search'] ?? '')));

try {
    // Base scope = the date window. recordsTotal counts within it, so we never
    // COUNT the whole unbounded table for the "filtered from N" figure.
    $baseWhere = 'WHERE 1=1';
    $baseParams = [];
    if ($from !== '') { $baseWhere .= ' AND con.contribution_date >= :from'; $baseParams['from'] = $from; }
    if ($to   !== '') { $baseWhere .= ' AND con.contribution_date <= :to';   $baseParams['to']   = $to; }

    // Filtered scope = base + the column filters + smart search.
    $where  = $baseWhere;
    $params = $baseParams;
    if ($status  !== '') { $where .= ' AND con.status = :status';            $params['status']  = $status; }
    if ($type    !== '') { $where .= ' AND con.contribution_type = :type';   $params['type']    = $type; }
    if ($account !== '') { $where .= ' AND con.account = :account';          $params['account'] = $account; }
    if ($member_id > 0)  { $where .= ' AND con.member_id = :mid';            $params['mid']     = $member_id; }
    if ($search !== '') {
        // Search the receipt (bounded by the date window) and resolve member
        // names against the small customers table — never a LIKE across the huge
        // contributions table on a non-indexed text column.
        $where .= " AND (con.receipt_number LIKE :s
                    OR con.member_id IN (
                        SELECT customer_id FROM customers
                        WHERE TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) LIKE :s2
                    ))";
        $params['s']  = '%' . $search . '%';
        $params['s2'] = '%' . $search . '%';
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM contributions con $baseWhere");
    $totalStmt->execute($baseParams);
    $recordsTotal = (int) $totalStmt->fetchColumn();

    $filtStmt = $pdo->prepare("SELECT COUNT(*) FROM contributions con $where");
    $filtStmt->execute($params);
    $recordsFiltered = (int) $filtStmt->fetchColumn();

    // Whitelisted sortable columns (index → column) — never interpolate the
    // request's order column directly.
    $orderCols = [
        0 => 'con.contribution_date',
        1 => 'member_name',
        2 => 'con.receipt_number',
        3 => 'con.account',
        4 => 'con.contribution_type',
        5 => 'con.amount',
        6 => 'con.status',
    ];
    $orderIdx = (int) ($_GET['order'][0]['column'] ?? 0);
    $orderBy  = $orderCols[$orderIdx] ?? 'con.contribution_date';
    $orderDir = strtolower((string) ($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $stmt = $pdo->prepare("
        SELECT con.contribution_id, con.contribution_date, con.receipt_number, con.account,
               con.contribution_type, con.amount, con.status,
               TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name
          FROM contributions con
          LEFT JOIN customers c ON con.member_id = c.customer_id
          $where
         ORDER BY $orderBy $orderDir, con.contribution_id DESC
         LIMIT $start, $length
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $data,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'draw' => $draw, 'data' => []]);
}
