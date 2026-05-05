<?php
// api/get_member_by_phone.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$phone = trim($_GET['phone'] ?? '');

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number missing']);
    exit();
}

try {
    // Search by Phone or ID (Member ID)
    $stmt = $pdo->prepare("
        SELECT customer_id, customer_name, first_name, last_name, phone, status 
        FROM customers 
        WHERE phone LIKE ? OR customer_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? 
        LIMIT 10
    ");
    $kw = '%' . $phone . '%';
    $stmt->execute([$kw, $kw, $kw, $kw]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($members) {
        $results = [];
        foreach ($members as $m) {
            $results[] = [
                'customer_id' => $m['customer_id'],
                'name' => $m['customer_name'] ?: ($m['first_name'] . ' ' . $m['last_name']),
                'phone' => $m['phone'],
                'status' => $m['status']
            ];
        }
        echo json_encode(['success' => true, 'members' => $results]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Member not found']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
