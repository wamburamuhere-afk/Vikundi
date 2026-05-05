<?php
// File: api/log_action.php
// Lightweight endpoint to log client-side actions (e.g. Print, Export) via AJAX POST

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$action      = trim($_POST['action']      ?? 'Action');
$module      = trim($_POST['module']      ?? 'System');
$description = trim($_POST['description'] ?? '');
$reference   = trim($_POST['reference']   ?? '');

// Basic sanitization — allow only known safe actions
$allowed_actions = ['Printed', 'Exported', 'Viewed', 'Downloaded'];
if (!in_array($action, $allowed_actions)) {
    $action = 'Action';
}

try {
    logActivity($action, $module, $description ?: "$action on $module", $reference);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
