<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthenticated');
    }

    $notification_id = $_POST['notification_id'] ?? 0;
    $user_id = $_SESSION['user_id'];

    if (!$notification_id) {
        throw new Exception('Notification ID is required');
    }

    $stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$notification_id, $user_id]);

    echo json_encode(['success' => true, 'message' => 'Notification deleted successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
