<?php
// ajax/login.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!defined('ROOT_DIR')) {
    require_once __DIR__ . '/../roots.php'; 
}
if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
    $login_input = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($login_input) || empty($password)) {
        $response['message'] = 'Please enter both your email/username and password.';
        echo json_encode($response);
        exit;
    }

    // Fetch user from database - check both username and email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status != 'deleted'");
    $stmt->execute([$login_input, $login_input]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
	


    if ($user) {
        if (password_verify($password, $user['password'])) {
            // Check specific restricted statuses
            if ($user['status'] === 'pending') {
                $response['message'] = 'Your account is pending approval by the Admin. Please wait for further instructions.';
                echo json_encode($response);
                exit;
            }
            if ($user['status'] === 'rejected') {
                $response['message'] = 'Your membership application has been rejected.';
                echo json_encode($response);
                exit;
            }
            if ($user['status'] === 'inactive' || $user['status'] === 'suspended') {
                $response['message'] = 'Your account is currently disabled. Please contact the Admin.';
                echo json_encode($response);
                exit;
            }
            
            // Success - Proceed with session setup
            require_once __DIR__ . '/../core/permissions.php';
            
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role_id'] = $user['role_id'] ?? 0;
            $_SESSION['role'] = $user['role_name'] ?? $user['user_role'] ?? 'user';
            $_SESSION['user_role'] = $user['user_role'] ?? 'user';
            $_SESSION['preferred_language'] = $user['preferred_language'] ?? 'en';
            $_SESSION['username'] = $user['username'];
            
            // Update last login timestamp
            $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
            $update_stmt->execute([$user['user_id']]);

            // Track the login in activity logs
            require_once __DIR__ . '/../includes/activity_logger.php';
            $fullname = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'];
            logLogin($user['user_id'], $fullname);

            if (function_exists('loadUserPermissions')) {
                loadUserPermissions($_SESSION['role_id']);
            }
            
            $response['success'] = true;
        } else {
            // Found user but wrong password
            $response['message'] = 'Incorrect password. Please try again.';
        }
    } else {
        // User not found at all
        $response['message'] = 'User account not found or email/username is incorrect.';
    }
}

echo json_encode($response);
?>