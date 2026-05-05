<?php
// actions/update_user_role.php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';

// Check if user is logged in
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Hujalogin.']);
    exit();
}

// Check privileges
$stmt = $pdo->prepare("SELECT u.user_role, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);
$current_user_role = $user_data['role_name'] ?? $user_data['user_role'] ?? 'Member';

$viongozi_roles = ['Admin', 'Secretary', 'Katibu', 'Treasurer', 'Mhasibu'];
if (!in_array($current_user_role, $viongozi_roles)) {
    echo json_encode(['success' => false, 'message' => 'Huna mamlaka ya kubadilisha nafasi ya mwanachama.']);
    exit();
}

// Get request data
$target_user_id = $_POST['user_id'] ?? null;
$new_role = $_POST['role'] ?? null;

if (!$target_user_id || !$new_role) {
    echo json_encode(['success' => false, 'message' => 'Data hazijakamilika.']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Mapping roles to IDs (Adjust based on your roles table if necessary)
    $role_map = [
        'Admin' => 1,
        'Member' => 2,
        'Secretary' => 3,
        'Treasurer' => 4,
        'Katibu' => 3 
    ];
    
    $role_id = $role_map[$new_role] ?? 2;

    // Update users table - syncing all role-related columns
    $stmt = $pdo->prepare("UPDATE users SET user_role = ?, role = ?, role_id = ? WHERE user_id = ?");
    $stmt->execute([$new_role, $new_role, $role_id, $target_user_id]);

    $pdo->commit();

    // ── Activity Log ──────────────────────────────────────────────────────────
    require_once __DIR__ . '/../includes/activity_logger.php';
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $log_desc = $is_sw
        ? "Nafasi ya mwanachama #$target_user_id imebadilishwa kuwa: $new_role"
        : "Member #$target_user_id role changed to: $new_role";
    logUpdate('Users', "USER#$target_user_id", "USER#$target_user_id");
    // ─────────────────────────────────────────────────────────────────────────

    $msg = $is_sw ? "Nafasi ya mwanachama imebadilishwa kuwa $new_role kikamilifu." : "Member role updated to $new_role successfully.";
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    $pdo->rollBack();
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $err_msg = $is_sw ? "Hitilafu imetokea: " : "An error occurred: ";
    echo json_encode(['success' => false, 'message' => $err_msg . $e->getMessage()]);
}
