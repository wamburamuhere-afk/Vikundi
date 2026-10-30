<?php
// actions/save_member_entrance_fee.php — leadership sets a member's own entrance fee
// (customers.entrance_fee). Blank clears it, and the member goes back to the group's fee.
// Read by cs_entrance_for() in includes/contribution_standing.php.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
requirePermissionJson('edit', 'customers'); // leadership only

$is_sw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = isset($_POST['user_id']) && ctype_digit((string) $_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$raw     = trim(str_replace(',', '', (string) ($_POST['entrance_fee'] ?? '')));

if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0)) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Weka kiasi sahihi, au acha wazi.' : 'Enter a valid amount, or leave it blank.']);
    exit;
}
$fee = $raw === '' ? null : round((float) $raw, 2);

try {
    $r = $pdo->prepare("SELECT customer_id, entrance_fee FROM customers WHERE user_id = ? LIMIT 1");
    $r->execute([$user_id]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Mwanachama hajapatikana.' : 'Member not found.']);
        exit;
    }
    $customer_id = (int) $row['customer_id'];

    $pdo->prepare("UPDATE customers SET entrance_fee = ? WHERE customer_id = ?")->execute([$fee, $customer_id]);

    $old = $row['entrance_fee'] === null ? 'group fee' : number_format((float) $row['entrance_fee'], 0);
    $new = $fee === null ? 'group fee' : number_format($fee, 0);
    logUpdate('Members', "Entrance fee: $old -> $new", "MEMBER#$customer_id");

    echo json_encode(['success' => true, 'entrance_fee' => $fee,
        'message' => $is_sw ? 'Kiingilio kimehifadhiwa.' : 'Entrance fee saved.']);
} catch (Throwable $e) {
    error_log('save_member_entrance_fee: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Imeshindikana kuhifadhi.' : 'Could not save.']);
}
