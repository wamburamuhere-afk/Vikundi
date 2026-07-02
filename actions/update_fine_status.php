<?php
// actions/update_fine_status.php — mark a fine paid / waived / pending.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/fine_helpers.php';

header('Content-Type: application/json');
requirePermissionJson('edit', 'manage_fines'); // audit H3 — leadership only

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$fine_id = isset($_POST['fine_id']) && ctype_digit((string) $_POST['fine_id']) ? (int) $_POST['fine_id'] : 0;
$status  = vk_normalize_fine_status($_POST['status'] ?? '');

if ($fine_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Faini haijapatikana.' : 'Fine not found.']);
    exit;
}
// Only allow the real transitions (guard against a bad value silently becoming 'pending').
if (!in_array(($_POST['status'] ?? ''), vk_fine_statuses(), true)) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Hali si sahihi.' : 'Invalid status.']);
    exit;
}

try {
    $row = $pdo->prepare("SELECT customer_id FROM fines WHERE fine_id = ?");
    $row->execute([$fine_id]);
    if ($row->fetchColumn() === false) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Faini haijapatikana.' : 'Fine not found.']);
        exit;
    }

    $pdo->prepare("UPDATE fines SET status = ? WHERE fine_id = ?")->execute([$status, $fine_id]);
    logUpdate('Fines', "Status: $status", "FINE#$fine_id");

    $labels = ['paid' => $is_sw ? 'imelipwa' : 'paid', 'waived' => $is_sw ? 'imesamehewa' : 'waived', 'pending' => $is_sw ? 'inasubiri' : 'pending'];
    echo json_encode(['success' => true, 'status' => $status,
        'message' => ($is_sw ? 'Faini ' : 'Fine marked ') . ($labels[$status] ?? $status) . '.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
