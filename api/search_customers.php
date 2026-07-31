<?php
require_once __DIR__ . '/../includes/config.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../core/permissions.php';
requirePermissionJson('view', 'customers');

header('Content-Type: application/json');

$q = $_GET['q'] ?? '';

try {
    $sql = "SELECT customer_id as id, CONCAT(first_name, ' ', last_name, ' (', phone, ')') as text FROM customers WHERE (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?) AND is_deceased = 0 LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['results' => $results]);

} catch (Exception $e) {
    echo json_encode(['results' => []]);
}
?>
