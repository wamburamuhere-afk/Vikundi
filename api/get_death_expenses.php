<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$draw = $_GET['draw'] ?? 1;
$start = $_GET['start'] ?? 0;
$length = $_GET['length'] ?? 10;
$search_value = $_GET['search']['value'] ?? '';

try {
    $where = "WHERE (c.first_name LIKE :q OR c.last_name LIKE :q OR d.deceased_name LIKE :q OR (d.phone_number IS NOT NULL AND d.phone_number LIKE :q))";
    $params = ['q' => "%$search_value%"];

    // Count All (Total records in table that have valid members)
    $stmt_total = $pdo->query("SELECT COUNT(*) FROM death_expenses d JOIN customers c ON d.member_id = c.customer_id");
    $recordsTotal = (int)$stmt_total->fetchColumn();

    // Count Filtered (Records matching search)
    $stmt_filtered = $pdo->prepare("SELECT COUNT(*) FROM death_expenses d JOIN customers c ON d.member_id = c.customer_id $where");
    $stmt_filtered->execute($params);
    $recordsFiltered = (int)$stmt_filtered->fetchColumn();

    // Data query
    $sql = "SELECT d.*, CONCAT(c.first_name, ' ', c.last_name) as member_name 
            FROM death_expenses d 
            JOIN customers c ON d.member_id = c.customer_id 
            $where 
            ORDER BY d.created_at DESC 
            LIMIT :start, :length";
            
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':q', "%$search_value%", PDO::PARAM_STR);
    $stmt->bindValue(':start', (int)$start, PDO::PARAM_INT);
    $stmt->bindValue(':length', (int)$length === -1 ? 999999 : (int)$length, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals
    $stats_stmt = $pdo->query("SELECT SUM(amount) as totalAmount, COUNT(*) as count FROM death_expenses");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $month_stmt = $pdo->query("SELECT SUM(amount) FROM death_expenses WHERE MONTH(expense_date) = MONTH(CURRENT_DATE) AND YEAR(expense_date) = YEAR(CURRENT_DATE)");
    $monthTotal = $month_stmt->fetchColumn() ?: 0;

    echo json_encode([
        'draw' => intval($draw),
        'recordsTotal' => intval($recordsTotal),
        'recordsFiltered' => intval($recordsFiltered),
        'data' => $data,
        'totalAmount' => $stats['totalAmount'] ?: 0,
        'monthTotal' => $monthTotal
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
