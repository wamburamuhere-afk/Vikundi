<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get parameters from DataTables
$draw = $_GET['draw'] ?? 1;
$start = $_GET['start'] ?? 0;
$length = $_GET['length'] ?? 10;
$searchValue = $_GET['search']['value'] ?? '';

// Custom filters
$expense_account_id = $_GET['expense_account_id'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Get order parameters
$orderColumnIndex = $_GET['order'][0]['column'] ?? 0;
$orderDirection = $_GET['order'][0]['dir'] ?? 'desc';

// Define column mapping
$columns = [
    'e.expense_date',
    'e.description',
    'ea.account_name',
    'e.amount',
    'ba.account_name',
    'e.reference_number',
    'e.status',
    'u.username',
    ''
];

// Base query
$query = "SELECT SQL_CALC_FOUND_ROWS 
          e.*, 
          ea.account_name as expense_account_name, 
          ba.account_name as bank_account_name,
          u.username as created_by_name
          FROM expenses e
          LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
          LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id
          LEFT JOIN users u ON e.created_by = u.user_id
          WHERE 1=1";

$countQuery = "SELECT COUNT(*) FROM expenses e 
               LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
               LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id
               WHERE 1=1";

$params = [];

// Apply filters
if (!empty($expense_account_id)) {
    $query .= " AND e.expense_account_id = :expense_account_id";
    $countQuery .= " AND e.expense_account_id = :expense_account_id";
    $params[':expense_account_id'] = $expense_account_id;
}

if (!empty($status)) {
    $query .= " AND e.status = :status";
    $countQuery .= " AND e.status = :status";
    $params[':status'] = $status;
}

if (!empty($date_from)) {
    $query .= " AND e.expense_date >= :date_from";
    $countQuery .= " AND e.expense_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND e.expense_date <= :date_to";
    $countQuery .= " AND e.expense_date <= :date_to";
    $params[':date_to'] = $date_to;
}

// Add search filter if specified
if (!empty($searchValue)) {
    $searchCond = " AND (e.description LIKE :search1 OR 
                    e.reference_number LIKE :search2 OR 
                    ea.account_name LIKE :search3 OR
                    ba.account_name LIKE :search4 OR
                    e.amount LIKE :search5)";
    $query .= $searchCond;
    $countQuery .= $searchCond;
    $params[':search1'] = "%$searchValue%";
    $params[':search2'] = "%$searchValue%";
    $params[':search3'] = "%$searchValue%";
    $params[':search4'] = "%$searchValue%";
    $params[':search5'] = "%$searchValue%";
}

// Get total filtered records
$countParams = $params;
$countStmt = $pdo->prepare($countQuery);
foreach ($countParams as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalFiltered = $countStmt->fetchColumn();
$countStmt->closeCursor();

// Add sorting
if (isset($columns[$orderColumnIndex]) && !empty($columns[$orderColumnIndex])) {
    $orderBy = $columns[$orderColumnIndex];
    $query .= " ORDER BY $orderBy $orderDirection";
} else {
    $query .= " ORDER BY e.expense_date DESC, e.created_at DESC";
}

// Add pagination
$query .= " LIMIT :start, :length";
$params[':start'] = (int)$start;
$params[':length'] = (int)$length;

// Prepare and execute main query
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    if ($key === ':start' || $key === ':length') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

// Get total records without filters
$totalRecords = $pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn();

// Get Stats
$statsQuery = "SELECT 
               SUM(amount) as total_expenses,
               SUM(CASE WHEN DATE_FORMAT(expense_date, '%Y-%m') = :current_month THEN amount ELSE 0 END) as month_total,
               SUM(CASE WHEN DATE_FORMAT(expense_date, '%Y') = :current_year THEN amount ELSE 0 END) as year_total
               FROM expenses";
$statsStmt = $pdo->prepare($statsQuery);
$statsStmt->execute([
    ':current_month' => date('Y-m'),
    ':current_year' => date('Y')
]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Prepare response
$response = [
    'draw' => (int)$draw,
    'recordsTotal' => (int)$totalRecords,
    'recordsFiltered' => (int)$totalFiltered,
    'data' => $expenses,
    'totalExpenses' => (float)($stats['total_expenses'] ?? 0),
    'monthTotal' => (float)($stats['month_total'] ?? 0),
    'yearTotal' => (float)($stats['year_total'] ?? 0)
];

echo json_encode($response);
?>
