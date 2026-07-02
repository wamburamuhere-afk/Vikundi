<?php
// actions/save_registration_number.php — leadership assigns/edits a member's
// (leadership-given) registration number. Free text, unique across members.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
requirePermissionJson('edit', 'customers'); // audit H3 — leadership only

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$customer_id = isset($_POST['customer_id']) && ctype_digit((string) $_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
$user_id     = isset($_POST['user_id'])     && ctype_digit((string) $_POST['user_id'])     ? (int) $_POST['user_id']     : 0;
$reg = trim((string) ($_POST['registration_number'] ?? ''));

try {
    // Accept either the customer id or the user id (the approval prompt has user_id).
    if ($customer_id <= 0 && $user_id > 0) {
        $r = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1");
        $r->execute([$user_id]);
        $customer_id = (int) ($r->fetchColumn() ?: 0);
    }
    if ($customer_id <= 0) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Mwanachama hajapatikana.' : 'Member not found.']);
        exit;
    }

    $chk = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
    $chk->execute([$customer_id]);
    if ($chk->fetchColumn() === false) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Mwanachama hajapatikana.' : 'Member not found.']);
        exit;
    }

    // Unique across members (blank clears it; a non-empty value must be unused).
    if ($reg !== '') {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE registration_number = ? AND customer_id <> ?");
        $dup->execute([$reg, $customer_id]);
        if ((int) $dup->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => $is_sw
                ? 'Namba hii ya usajili tayari inatumiwa na mwanachama mwingine.'
                : 'This registration number is already used by another member.']);
            exit;
        }
    }

    $pdo->prepare("UPDATE customers SET registration_number = ? WHERE customer_id = ?")
        ->execute([$reg !== '' ? $reg : null, $customer_id]);

    logUpdate('Members', 'Registration number', "MEMBER#$customer_id");
    echo json_encode(['success' => true, 'registration_number' => $reg,
        'message' => $is_sw ? 'Namba ya usajili imehifadhiwa.' : 'Registration number saved.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
