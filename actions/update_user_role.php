<?php
// actions/update_user_role.php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_csrf.php'; // audit H6: valid CSRF token required

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

$viongozi_roles = ['Admin', 'Chairperson', 'Mwenyekiti', 'Secretary', 'Katibu', 'Treasurer', 'Mhasibu'];
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

    // Resolve the role id from the roles table by name.
    //
    // This used to be a hard-coded map, and it granted the opposite of what it
    // read as:
    //
    //     'Member' => 2,   // role_id 2 is CHAIRPERSON
    //     $role_id = $role_map[$new_role] ?? 2;
    //
    // Setting a user to "Member" therefore gave them role_id 2, which isAdmin()
    // and vk_api_is_admin() both treat as a full admin — a demotion that handed
    // out administrative access. The `?? 2` default did the same for any role
    // name not in the map. The real Member role is id 13 on a freshly seeded
    // install and 15 on the live system, which is exactly why ids must never be
    // hard-coded here.
    $rs = $pdo->prepare('SELECT role_id FROM roles WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
    $rs->execute([$new_role]);
    $role_id = (int) ($rs->fetchColumn() ?: 0);

    if ($role_id <= 0) {
        // Refuse rather than fall back. A default here is a silent grant of
        // whatever role that id happens to be on this install.
        $pdo->rollBack();
        $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
        echo json_encode(['success' => false, 'message' => $is_sw
            ? "Nafasi \"$new_role\" haipo kwenye mfumo."
            : "The role \"$new_role\" does not exist in this system."]);
        exit();
    }

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
