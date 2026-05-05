<?php
// actions/update_user_status.php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';

// Check if user is logged in and is a leader (Admin, Secretary, Katibu)
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Hujalogin.']);
    exit();
}

// Get user role with a join to roles table
$stmt = $pdo->prepare("SELECT u.user_role, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);
$current_user_role = $user_data['role_name'] ?? $user_data['user_role'] ?? 'Member';

$viongozi_roles = ['Admin', 'Secretary', 'Katibu', 'Treasurer', 'Mhasibu'];
if (!in_array($current_user_role, $viongozi_roles)) {
    echo json_encode(['success' => false, 'message' => 'Huna mamlaka ya kubadilisha hali ya mwanachama.']);
    exit();
}

// Get request data
$target_user_id = $_POST['user_id'] ?? null;
$customer_id = $_POST['customer_id'] ?? null;
$new_status = $_POST['status'] ?? null;

if ((!$target_user_id && !$customer_id) || !$new_status) {
    echo json_encode(['success' => false, 'message' => 'Data hazijakamilika.']);
    exit();
}

try {
    $pdo->beginTransaction();

    if ($new_status === 'deleted') {
        if ($target_user_id) {
            $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            $email = $stmt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$target_user_id]);

            if ($email) {
                $pdo->prepare("DELETE FROM customers WHERE email = ?")->execute([$email]);
            }
        }
        
        if ($customer_id) {
            $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$customer_id]);
        }
        
        $pdo->commit();
        // ── Activity Log ──────────────────────────────────────────────────────
        require_once __DIR__ . '/../includes/activity_logger.php';
        $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
        $ref = $target_user_id ? "USER#$target_user_id" : "CUST#$customer_id";
        $log_desc = $is_sw ? "Mwanachama/Mtumiaji $ref amefutwa kabisa" : "Member/User $ref permanently deleted";
        logDelete('Members', $ref, $ref);
        // ─────────────────────────────────────────────────────────────────────
        $msg = $is_sw ? 'Mtumiaji/Mwanachama amefutwa kabisa.' : 'User/Member removed successfully.';
        echo json_encode(['success' => true, 'message' => $msg]);
        exit();
    }

    // Normal status changes
    if ($target_user_id) {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $stmt->execute([$new_status, $target_user_id]);

        $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
        $stmt->execute([$target_user_id]);
        $email = $stmt->fetchColumn();
        if ($email) {
            $pdo->prepare("UPDATE customers SET status = ? WHERE email = ?")->execute([$new_status, $email]);
        }
    } elseif ($customer_id) {
        $pdo->prepare("UPDATE customers SET status = ? WHERE customer_id = ?")->execute([$new_status, $customer_id]);
    }

    $pdo->commit();
    // ── Activity Log ──────────────────────────────────────────────────────
    require_once __DIR__ . '/../includes/activity_logger.php';
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $ref = $target_user_id ? "USER#$target_user_id" : "CUST#$customer_id";
    $status_label = ($new_status === 'active') ? ($is_sw ? 'Imewashwa' : 'Activated') : ($is_sw ? 'Imezimwa' : 'Deactivated');
    $log_desc = $is_sw ? "Hali ya mwanachama $ref imebadilishwa kuwa: $new_status" : "Member $ref status changed to: $new_status";
    logUpdate('Members', $ref, $ref);
    // ─────────────────────────────────────────────────────────────────────
    $msg = $is_sw ? "Hali ya mwanachama imebadilishwa kuwa $status_label kikamilifu." : "Member status updated to $status_label successfully.";
    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    $pdo->rollBack();
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $err_msg = $is_sw ? "Hitilafu imetokea: " : "An error occurred: ";
    echo json_encode(['success' => false, 'message' => $err_msg . $e->getMessage()]);
}
