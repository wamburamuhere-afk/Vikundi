<?php
// actions/reset_password.php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';

$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$user_id = $_SESSION['reset_user_id'] ?? null;
$session_token = $_SESSION['reset_token'] ?? '';
$reset_time = $_SESSION['reset_time'] ?? 0;

if (!$user_id || empty($session_token) || (time() - $reset_time) > 3600) {
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please start over.']);
    exit();
}

if (empty($password) || strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit();
}

if ($password !== $confirm_password) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit();
}

try {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?");
    $stmt->execute([$hashed_password, $user_id]);

    // Clear session tokens
    unset($_SESSION['reset_token']);
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_time']);

    echo json_encode(['success' => true, 'message' => 'Password has been reset successfully! You can now log in with your new password.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
