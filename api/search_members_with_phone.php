<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$q = $_GET['q'] ?? '';

try {
    $stmt = $pdo->prepare("
        SELECT customer_id, first_name, last_name, phone 
        FROM customers c
        WHERE (first_name LIKE :q OR last_name LIKE :q OR phone LIKE :q) 
        AND status = 'active' 
        AND is_deceased = 0
        AND customer_id NOT IN (
            SELECT member_id FROM death_expenses WHERE (deceased_type = 'mwanachama' OR deceased_id = 'member') AND status != 'rejected'
        )
        LIMIT 20");
    $stmt->execute(['q' => "%$q%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function($r) {
        return [
            'id' => $r['customer_id'],
            'text' => $r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['phone'] . ')',
            'phone' => $r['phone']
        ];
    }, $results);

    echo json_encode(['results' => $formatted]);
} catch (Exception $e) {
    echo json_encode(['results' => [], 'error' => $e->getMessage()]);
}
?>
