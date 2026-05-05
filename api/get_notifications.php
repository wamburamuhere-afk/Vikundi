<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';

// Suppress errors and clean buffer to ensure valid JSON
error_reporting(0);
ini_set('display_errors', 0);
if (ob_get_level()) while (ob_get_level()) ob_end_clean();
ob_start();
header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Unauthenticated']);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $draw = $_GET['draw'] ?? 1;
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 10);
    
    // Filters
    $type = $_GET['type'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $is_read = $_GET['is_read'] ?? '';

    $where = ["n.user_id = ?"];
    $params = [$userId];

    if (!empty($type)) { $where[] = "type = ?"; $params[] = $type; }
    if (!empty($priority)) { $where[] = "priority = ?"; $params[] = $priority; }
    if ($is_read !== '') { $where[] = "is_read = ?"; $params[] = $is_read; }

    $where_clause = "WHERE " . implode(" AND ", $where);

    // Fetch Data
    $query = "SELECT n.*, n.loan_id as related_loan_id, CONCAT(c.first_name, ' ', c.last_name) as customer_name 
              FROM notifications n 
              LEFT JOIN customers c ON n.customer_id = c.customer_id
              $where_clause ORDER BY n.created_at DESC LIMIT $start, $length";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get counts for DataTables and Stats
    $total_count = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId")->fetchColumn();
    $unread_count = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId AND is_read = 0")->fetchColumn();
    $high_priority_unread = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId AND is_read = 0 AND priority = 'high'")->fetchColumn();
    $today_count = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId AND DATE(created_at) = CURDATE()")->fetchColumn();

    $formatted = [];
    foreach ($data as $row) {
        $formatted[] = [
            'notification_id' => $row['notification_id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'type' => ucfirst($row['type']),
            'priority' => $row['priority'],
            'is_read' => (int)$row['is_read'],
            'created_at' => $row['created_at'],
            'action_url' => $row['action_url'],
            'related_loan_id' => $row['related_loan_id'],
            'customer_name' => $row['customer_name']
        ];
    }

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'draw' => (int)$draw,
        'recordsTotal' => $total_count,
        'recordsFiltered' => $total_count,
        'data' => $formatted,
        'stats' => [
            'total_notifications' => $total_count,
            'unread_count' => $unread_count,
            'high_priority_unread' => $high_priority_unread,
            'today_count' => $today_count
        ]
    ]);

} catch (Throwable $e) {
    if (ob_get_level()) while (ob_get_level()) ob_end_clean();
    echo json_encode(['error' => $e->getMessage(), 'data' => [], 'stats' => []]);
}
?>
