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

// Authorisation. This handler previously checked only that SOMEONE was signed
// in. group_settings.php gates its form to admins and the Secretary, but hiding
// a form is not a control: any member could POST here directly and rename the
// group, set monthly_contribution to 1 or zero the fines. monthly_contribution
// drives the arrears calculation, so that single request clears every member's
// arrears across the whole group. Verified exploitable against a running
// instance before this was added.
require_once __DIR__ . '/../includes/roles.php';

$vk_role = '';
try {
    $vk_stmt = $pdo->prepare(
        'SELECT r.role_name FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?'
    );
    $vk_stmt->execute([$_SESSION['user_id']]);
    $vk_role = (string) ($vk_stmt->fetchColumn() ?: ($_SESSION['user_role'] ?? ''));
} catch (Throwable $e) {
    $vk_role = (string) ($_SESSION['user_role'] ?? '');
}

$vk_is_secretary = in_array(strtolower(trim($vk_role)), ['secretary', 'katibu'], true);

if (!vk_role_is_admin($_SESSION['role_id'] ?? null, $vk_role) && !$vk_is_secretary) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => ($_SESSION['preferred_language'] ?? 'en') === 'sw'
        ? 'Huna ruhusa ya kubadilisha mipangilio ya kikundi.'
        : 'You do not have permission to change group settings.']);
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
        // SVG deliberately removed. assets/images/ is web-served, and an SVG is
        // a script-carrying document: <svg><script>…</script></svg> served from
        // the app's own origin is stored XSS with the session cookie in reach.
        // Raster formats cannot do that. Existing SVG logos keep rendering; only
        // new uploads are refused.
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        // The extension is attacker-chosen, the bytes are not, so both are
        // checked — shared with the mobile API so one whitelist governs both.
        require_once __DIR__ . '/../includes/api_upload.php';
        $vk_mime_ok = in_array(
            vk_api_sniff_mime($file_tmp),
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            true
        );

        if (in_array($ext, $allowed_exts) && $vk_mime_ok) {
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
