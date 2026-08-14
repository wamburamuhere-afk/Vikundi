<?php
// actions/save_fine.php — leadership records a fine against a member.
//
// Until now the only thing that ever created a fine was the meeting-absence sweep,
// so there was no way to record an ordinary one. The Transactions form appeared to
// offer it — a "Fine" option in the contribution type list — but that wrote a
// CONTRIBUTION with contribution_type='fine', which My Fines never reads. The money
// landed in the books and the fine vanished. That option is gone; this is the way.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/fine_helpers.php';
require_once __DIR__ . '/../includes/activity_logger.php';
global $pdo;

header('Content-Type: application/json');

// Recording a fine is a leadership act — the same permission that already governs
// marking one paid or waiving it.
requirePermissionJson('create', 'manage_fines');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$err = fn(string $m) => json_encode(['success' => false, 'message' => $m]);

$customer_id = isset($_POST['customer_id']) && ctype_digit((string) $_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
$reason      = trim((string) ($_POST['reason'] ?? ''));
$status      = vk_normalize_fine_status($_POST['status'] ?? 'pending');
$amount_raw  = str_replace([',', ' '], '', (string) ($_POST['amount'] ?? ''));

if ($customer_id <= 0) {
    echo $err($is_sw ? 'Chagua mwanachama.' : 'Choose a member.');
    exit;
}
if (!is_numeric($amount_raw) || (float) $amount_raw <= 0) {
    echo $err($is_sw ? 'Weka kiasi sahihi.' : 'Enter a valid amount.');
    exit;
}
$amount = round((float) $amount_raw, 2);

// A fine with no reason is a figure nobody can defend when the member asks why.
if ($reason === '') {
    echo $err($is_sw ? 'Andika sababu ya faini.' : 'Give a reason for the fine.');
    exit;
}

$c = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', NULLIF(TRIM(first_name), ''), NULLIF(TRIM(middle_name), ''), NULLIF(TRIM(last_name), '')))
                      FROM customers WHERE customer_id = ? AND status <> 'deleted'");
$c->execute([$customer_id]);
$member_name = $c->fetchColumn();
if (!$member_name) {
    echo $err($is_sw ? 'Mwanachama hajapatikana.' : 'Member not found.');
    exit;
}

try {
    $pdo->prepare("INSERT INTO fines (customer_id, amount, reason, status, created_at) VALUES (?,?,?,?,NOW())")
        ->execute([$customer_id, $amount, $reason, $status]);
    $fine_id = (int) $pdo->lastInsertId();

    logCreate('Fines', $member_name . ' — TSh ' . number_format($amount, 0), 'FINE#' . $fine_id);

    echo json_encode([
        'success' => true,
        'message' => $is_sw ? 'Faini imerekodiwa.' : 'Fine recorded.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo $err($is_sw ? 'Imeshindikana kuhifadhi faini.' : 'Could not save the fine.');
}
