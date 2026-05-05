<?php
// actions/save_group_settings.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

// Ensure database connection
if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Hujaingia kwenye mfumo.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Ombi si sahihi.']);
    exit();
}

// Keys we want to allow for updates
$allowed_keys = [
    'group_name', 'group_registration_number', 'group_founded_date', 'contribution_start_date', 'meeting_day', 'cycle_type', 'currency', 'max_members',
    'group_email', 'group_phone', 'group_postal_address', 'group_physical_address', 'group_tin', 'group_vrn', 'group_website',
    'monthly_contribution', 'entrance_fee', 'agm_fee', 'contribution_grace_days',
    'loan_interest_rate', 'loan_max_term_months', 'loan_multiplier', 'loan_grace_days',
    'fine_late_meeting', 'fine_late_contribution', 'fine_late_loan_payment', 'fine_absent_meeting',
    'shareout_month', 'profit_distribution_pct', 'current_cycle_year', 'auto_calculate_shareout',
    'deadline_day', 'deadline_time', 'auto_termination'
];

$lang = $_SESSION['preferred_language'] ?? 'en';

try {
    // Prepare once, execute many
    $stmt = $pdo->prepare("
        REPLACE INTO group_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
    ");

    $updated_count = 0;
    foreach ($allowed_keys as $key) {
        if (isset($_POST[$key])) {
            $value = trim((string)($_POST[$key]));
            $stmt->execute([$key, $value]);
            $updated_count++;
        } elseif ($key === 'auto_termination' && !isset($_POST[$key])) {
             // Handle switch/checkbox that is off
             $stmt->execute(['auto_termination', 'off']);
             $updated_count++;
        }
    }

    // HANDLE LOGO UPLOAD
    if (isset($_FILES['group_logo']) && $_FILES['group_logo']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['group_logo']['tmp_name'];
        $file_name = $_FILES['group_logo']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];

        if (in_array($ext, $allowed_exts)) {
            $new_name = 'group_logo_' . time() . '.' . $ext;
            $upload_path = __DIR__ . '/../assets/images/' . $new_name;
            
            if (!is_dir(__DIR__ . '/../assets/images/')) {
                mkdir(__DIR__ . '/../assets/images/', 0777, true);
            }

            if (move_uploaded_file($file_tmp, $upload_path)) {
                $stmt->execute(['group_logo', $new_name]);
                $updated_count++;
            }
        }
    }

    if ($updated_count > 0) {
        // ── Activity Log ──────────────────────────────────────────────────────
        require_once __DIR__ . '/../includes/activity_logger.php';
        $log_desc = $lang === 'sw'
            ? "Mipangilio ya kikundi imebadilishwa ($updated_count vipengele)"
            : "Group settings updated ($updated_count fields changed)";
        logUpdate('Group Settings', 'System Configuration', 'SETTINGS');
        // ─────────────────────────────────────────────────────────────────────

        $msg = ($lang === 'sw') ? 'Mipangilio imehifadhiwa.' : 'Settings saved successfully.';
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        $msg = ($lang === 'sw') ? 'Hakuna data iliyotolewa.' : 'No data provided.';
        echo json_encode(['success' => false, 'message' => $msg]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
