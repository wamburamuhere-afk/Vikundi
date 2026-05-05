<?php
// actions/approve_member.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Connection logic (Safier inclusion)
if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}

header('Content-Type: application/json');

// 2. Auth check using database for robustness
$user_id_session = $_SESSION['user_id'] ?? 0;
$stmt_check = $pdo->prepare("
    SELECT r.role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.role_id 
    WHERE u.user_id = ?
");
$stmt_check->execute([$user_id_session]);
$user_role_db = $stmt_check->fetchColumn();

$allowed_roles = ['Admin', 'Secretary', 'Katibu', 'Administrator'];
if (!in_array($user_role_db, $allowed_roles) && !in_array($_SESSION['user_role'] ?? '', $allowed_roles)) {
    $lang = $_SESSION['preferred_language'] ?? 'en';
    $msg = ($lang === 'sw') ? "Huna ruhusa ya kufanya kitendo hiki." : "You do not have permission to perform this action.";
    echo json_encode(['success' => false, 'message' => $msg . " (Role: " . ($user_role_db ?: 'None') . ")"]);
    exit;
}

$response = ['success' => false, 'message' => 'Unknown error'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!$user_id || !$action) {
        echo json_encode(['success' => false, 'message' => 'Data haijakamilika.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $status = ($action === 'approve') ? 'active' : 'rejected';
        
        // Update user status
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $stmt->execute([$status, $user_id]);

        // Get user email
        $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_email = $stmt->fetchColumn();

        if ($user_email) {
            $cust_status = ($status === 'active') ? 'active' : 'inactive';
            $stmt = $pdo->prepare("UPDATE customers SET status = ? WHERE email = ?");
            $stmt->execute([$cust_status, $user_email]);
        }

        $pdo->commit();

        // ── Activity Log ──────────────────────────────────────────────────────
        require_once __DIR__ . '/../includes/activity_logger.php';
        $lang = $_SESSION['preferred_language'] ?? 'en';
        if ($action === 'approve') {
            $desc = $lang === 'sw' ? "Aliidhini uanachama wa mwanachama #$user_id" : "Approved membership for member #$user_id";
            logActivity('Approved', 'Members', $desc, "MEMBER#$user_id");
            
            $success_msg = ($lang === 'sw') ? "Mwanachama ameidhinishwa kikamilifu." : "Member has been approved successfully.";
        } else {
            $desc = $lang === 'sw' ? "Alikataa uanachama wa mwanachama #$user_id" : "Rejected membership for member #$user_id";
            logActivity('Rejected', 'Members', $desc, "MEMBER#$user_id");
            
            $success_msg = ($lang === 'sw') ? "Ombi la mwanachama limekataliwa na kuondolewa." : "Member application has been rejected and removed.";
        }
        // ─────────────────────────────────────────────────────────────────────

        $response = ['success' => true, 'message' => $success_msg];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $err_prefix = ($lang === 'sw') ? "Hitilafu ya Database: " : "Database error: ";
        $response['message'] = $err_prefix . $e->getMessage();
    }
}

echo json_encode($response);
exit;
