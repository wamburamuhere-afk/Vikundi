<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$draw = $_GET['draw'] ?? 1;
$start = $_GET['start'] ?? 0;
$length = $_GET['length'] ?? 10;
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

try {
    $where = "WHERE 1=1";
    $params = [];

    if ($status) {
        $where .= " AND status = :status";
        $params['status'] = $status;
    }
    if ($date_from) {
        $where .= " AND expense_date >= :df";
        $params['df'] = $date_from;
    }
    if ($date_to) {
        $where .= " AND expense_date <= :dt";
        $params['dt'] = $date_to;
    }

    $total_stmt = $pdo->query("SELECT COUNT(*) FROM general_expenses");
    $recordsTotal = $total_stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM general_expenses $where ORDER BY created_at DESC LIMIT $start, $length");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals for stats
    $stats = $pdo->query("SELECT SUM(amount) FROM general_expenses WHERE status='approved'")->fetchColumn() ?: 0;
    $month = $pdo->query("SELECT SUM(amount) FROM general_expenses WHERE status='approved' AND MONTH(expense_date) = MONTH(CURRENT_DATE)")->fetchColumn() ?: 0;
    $year = $pdo->query("SELECT SUM(amount) FROM general_expenses WHERE status='approved' AND YEAR(expense_date) = YEAR(CURRENT_DATE)")->fetchColumn() ?: 0;

    echo json_encode([
        'draw' => intval($draw),
        'recordsTotal' => intval($recordsTotal),
        'recordsFiltered' => intval($recordsTotal),
        'data' => $data,
        'totalExpenses' => $stats,
        'monthTotal' => $month,
        'yearTotal' => $year
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
