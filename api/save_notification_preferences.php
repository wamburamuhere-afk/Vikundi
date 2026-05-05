<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthenticated');
    }

    $userId = $_SESSION['user_id'];
    
    // Process form data into JSON
    $prefs = [
        'email_notifications' => isset($_POST['email_notifications']),
        'push_notifications' => isset($_POST['push_notifications']),
        'sms_notifications' => isset($_POST['sms_notifications']),
        'contribution_alerts' => isset($_POST['contribution_alerts']),
        'member_alerts' => isset($_POST['member_alerts']),
        'system_alerts' => isset($_POST['system_alerts']),
        'quiet_hours_enabled' => isset($_POST['quiet_hours_enabled']),
        'quiet_hours_start' => $_POST['quiet_hours_start'] ?? '22:00',
        'quiet_hours_end' => $_POST['quiet_hours_end'] ?? '07:00'
    ];

    $prefsJson = json_encode($prefs);

    $stmt = $pdo->prepare("UPDATE users SET notification_preferences = ? WHERE user_id = ?");
    $stmt->execute([$prefsJson, $userId]);

    echo json_encode(['success' => true, 'message' => 'Notification preferences updated successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
