<?php
require_once __DIR__ . '/../includes/tenant.php'; // vk_storage_path(): this group's file storage
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
    'monthly_contribution', 'entrance_fee', 'mkoba_pays_entrance', 'agm_fee', 'contribution_grace_days',
    'share_price', 'shares_from_monthly', 'social_fund_amount', 'membership_deposit_amount',
    'financial_year_start_month', 'dividend_distribute_pct',
    'loan_interest_rate', 'loan_max_term_months', 'loan_multiplier', 'loan_grace_days',
    'fine_late_meeting', 'fine_late_contribution', 'fine_late_loan_payment', 'fine_absent_meeting',
    'shareout_month', 'profit_distribution_pct', 'current_cycle_year', 'auto_calculate_shareout',
    'deadline_day', 'deadline_time', 'auto_termination',
    'late_rules_enabled', 'late_rules_start_ym', 'late_fine_amount', 'late_fine_day', 'late_dormant_day',
];

$lang = $_SESSION['preferred_language'] ?? 'en';

// Settings of a feature the super-admin switched off for this group are not saved.
require_once __DIR__ . '/../includes/features.php';
$allowed_keys = array_values(array_diff($allowed_keys, vk_feature_hidden_settings()));

// ── Contribution rate history and late-payment rules: check before saving anything ──
require_once __DIR__ . '/../includes/late_rules.php';
$vk_errors = [];
$vk_rates_json = null; // null = this form did not send the rate table
if (isset($_POST['rate_table_sent'])) {
    // The web form always sends the marker; no rows means "no changes, one flat amount".
    $vk_amounts = is_array($_POST['rate_amount'] ?? null) ? $_POST['rate_amount'] : [];
    $vk_rows = [];
    foreach (array_values($vk_amounts) as $i => $amt) {
        $from = trim((string) (($_POST['rate_from'] ?? [])[$i] ?? ''));
        $amt  = trim((string) $amt);
        if ($amt === '' && $from === '') {
            continue; // an empty row the leader added and left
        }
        $vk_rows[] = ['from' => $from, 'amount' => $amt];
    }
    $vk_errors = array_merge($vk_errors, cr_validate($vk_rows, $lang === 'sw'));
    $vk_plan = cr_normalise($vk_rows);
    if (count($vk_plan) > 1) {
        $vk_rates_json = json_encode($vk_plan);
    } else {
        // One amount (or none) is just the plain monthly contribution.
        $vk_rates_json = '';
        if (count($vk_plan) === 1) {
            $_POST['monthly_contribution'] = (string) $vk_plan[0]['amount'];
        }
    }
}
if (isset($_POST['late_fine_day']) || isset($_POST['late_dormant_day'])) {
    $vk_fd = (int) ($_POST['late_fine_day'] ?? 9);
    $vk_dd = (int) ($_POST['late_dormant_day'] ?? 15);
    if ($vk_fd < 1 || $vk_fd > 28 || $vk_dd < 1 || $vk_dd > 28 || $vk_dd <= $vk_fd) {
        $vk_errors[] = $lang === 'sw'
            ? 'Siku ya faini na siku ya kusitishwa ziwe kati ya 1 na 28, na siku ya kusitishwa iwe baada ya siku ya faini.'
            : 'The fine day and the dormant day must be between 1 and 28, and the dormant day must come after the fine day.';
    }
    $vk_fa = trim((string) ($_POST['late_fine_amount'] ?? ''));
    if ($vk_fa !== '' && (!is_numeric($vk_fa) || (float) $vk_fa < 0)) {
        $vk_errors[] = $lang === 'sw' ? 'Weka kiasi sahihi cha faini.' : 'Enter a valid fine amount.';
    }
    // The checkbox is absent when off. When on, the rule starts this month unless a month is given.
    $_POST['late_rules_enabled'] = ($_POST['late_rules_enabled'] ?? '') === '1' ? '1' : '0';
    $vk_start = trim((string) ($_POST['late_rules_start_ym'] ?? ''));
    if ($vk_start !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $vk_start)) {
        $vk_errors[] = $lang === 'sw' ? 'Mwezi wa kuanza si sahihi.' : 'The start month is not valid.';
    }
    if ($_POST['late_rules_enabled'] === '1' && $vk_start === '') {
        $_POST['late_rules_start_ym'] = date('Y-m');
    }
}
if (isset($_POST['rate_table_sent'])) {
    // The web form's contributions section: the switch is absent from the post when off.
    $_POST['mkoba_pays_entrance'] = ($_POST['mkoba_pays_entrance'] ?? '') === '1' ? '1' : '0';
}
if ($vk_errors) {
    echo json_encode(['success' => false, 'message' => implode(' ', $vk_errors)]);
    exit();
}

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

    if ($vk_rates_json !== null) {
        $stmt->execute(['contribution_rates', $vk_rates_json]);
        $updated_count++;
        cr_sync_current($pdo); // the monthly amount follows the rate in force today
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
            // A single site keeps uploaded logos in its own assets/images/; on the shared
            // server they go in the group's storage (index.php serves them from there).
            $upload_path = vk_storage_dir('assets/images') . $new_name;

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
