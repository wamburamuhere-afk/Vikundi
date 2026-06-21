<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$draw = $_GET['draw'] ?? 1;
$start = $_GET['start'] ?? 0;
$length = $_GET['length'] ?? 10;
$search_value = $_GET['search']['value'] ?? '';

// Filter inputs (from the filter form on expenses.php)
$f_status = isset($_GET['f_status']) ? trim($_GET['f_status']) : '';
$f_year   = (isset($_GET['f_year'])   && $_GET['f_year']   !== '') ? (int)$_GET['f_year']   : 0;
$f_month  = (isset($_GET['f_month'])  && $_GET['f_month']  !== '') ? (int)$_GET['f_month']  : 0;
$f_member = (isset($_GET['f_member']) && $_GET['f_member'] !== '') ? (int)$_GET['f_member'] : 0;

try {
    // Base text search (DataTables search box)
    // NULL-safe so rows whose member no longer exists (LEFT JOIN -> NULL customer)
    // still match an empty search and remain visible.
    $conditions = ["(COALESCE(c.first_name,'') LIKE :q OR COALESCE(c.last_name,'') LIKE :q OR COALESCE(d.deceased_name,'') LIKE :q OR COALESCE(d.phone_number,'') LIKE :q)"];
    $params = ['q' => "%$search_value%"];

    // Dropdown filters
    $allowed_status = ['pending', 'reviewed', 'approved', 'rejected', 'inactive'];
    if ($f_status !== '' && in_array($f_status, $allowed_status, true)) {
        $conditions[] = "d.status = :f_status";
        $params['f_status'] = $f_status;
    }
    if ($f_year > 0) {
        $conditions[] = "YEAR(d.expense_date) = :f_year";
        $params['f_year'] = $f_year;
    }
    if ($f_month > 0) {
        $conditions[] = "MONTH(d.expense_date) = :f_month";
        $params['f_month'] = $f_month;
    }
    if ($f_member > 0) {
        $conditions[] = "d.member_id = :f_member";
        $params['f_member'] = $f_member;
    }

    $where = "WHERE " . implode(" AND ", $conditions);

    // Count All (total records in the table that have valid members)
    $stmt_total = $pdo->query("SELECT COUNT(*) FROM death_expenses d LEFT JOIN customers c ON d.member_id = c.customer_id");
    $recordsTotal = (int)$stmt_total->fetchColumn();

    // Count Filtered (records matching search + dropdown filters)
    $stmt_filtered = $pdo->prepare("SELECT COUNT(*) FROM death_expenses d LEFT JOIN customers c ON d.member_id = c.customer_id $where");
    $stmt_filtered->execute($params);
    $recordsFiltered = (int)$stmt_filtered->fetchColumn();

    // Data query
    $sql = "SELECT d.*,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))), ''), d.deceased_name, 'Unknown Member') as member_name
            FROM death_expenses d
            LEFT JOIN customers c ON d.member_id = c.customer_id
            $where
            ORDER BY d.created_at DESC
            LIMIT :start, :length";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':start', (int)$start, PDO::PARAM_INT);
    $stmt->bindValue(':length', (int)$length === -1 ? 999999 : (int)$length, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filtered total amount — so the "Total Assistance" card matches the
    // currently filtered rows (not the whole table).
    $sum_stmt = $pdo->prepare("SELECT COALESCE(SUM(d.amount), 0) FROM death_expenses d LEFT JOIN customers c ON d.member_id = c.customer_id $where");
    $sum_stmt->execute($params);
    $totalAmount = $sum_stmt->fetchColumn() ?: 0;

    // "This month" total is always the current calendar month (independent of filters).
    $month_stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM death_expenses WHERE MONTH(expense_date) = MONTH(CURRENT_DATE) AND YEAR(expense_date) = YEAR(CURRENT_DATE)");
    $monthTotal = $month_stmt->fetchColumn() ?: 0;

    echo json_encode([
        'draw' => intval($draw),
        'recordsTotal' => intval($recordsTotal),
        'recordsFiltered' => intval($recordsFiltered),
        'data' => $data,
        'totalAmount' => $totalAmount,
        'monthTotal' => $monthTotal
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
