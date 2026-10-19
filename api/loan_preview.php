<?php
// api/loan_preview.php — what a loan would cost, before anyone applies.
//
// Read-only. Returns the borrowing limit for the member on the chosen loan type
// and the repayment schedule for an amount and period, worked out by the same
// functions the real application uses — so the preview a member sees and the
// loan they get cannot differ. No arithmetic is repeated in the browser.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/member_savings.php';
require_once __DIR__ . '/../includes/loans.php';
global $pdo;

header('Content-Type: application/json');

$get = static fn(string $key): int => isset($_GET[$key]) && ctype_digit((string) $_GET[$key]) ? (int) $_GET[$key] : 0;

// A member previews for themselves only; leadership may preview for any member.
$me = vk_member_customer_id($pdo, (int) $_SESSION['user_id']);
$customer_id = (vl_can_manage() && $get('customer_id') > 0) ? $get('customer_id') : (int) ($me ?? 0);
if ($customer_id <= 0) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No member profile.']);
    exit;
}

$st = $pdo->prepare("SELECT * FROM vicoba_loan_types WHERE id = ? AND is_active = 1");
$st->execute([$get('loan_type_id')]);
$type = $st->fetch(PDO::FETCH_ASSOC);
if (!$type) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Loan type not found.']);
    exit;
}

$amount = str_replace([',', ' '], '', (string) ($_GET['amount'] ?? ''));
$amount = is_numeric($amount) ? max(0.0, (float) $amount) : 0.0;
$months = min(max($get('term_months'), 0), (int) $type['max_term_months']);

$limit    = vl_member_limit($pdo, $customer_id, $type);
$schedule = lr_schedule($amount, (float) $type['interest_rate'], $type['interest_type'], $months, date('Y-m-d'));

echo json_encode(['status' => 'success', 'data' => [
    'share_capital'       => $limit['share_capital'],
    'already_committed'   => $limit['committed'],
    'max_amount'          => $limit['max'],            // null = no limit
    'max_term_months'     => (int) $type['max_term_months'],
    'guarantors_required' => (int) $type['guarantors_required'],
    'within_limit'        => $limit['max'] === null || $amount <= $limit['max'],
    'totals'              => lr_schedule_totals($schedule),
    'schedule'            => $schedule,
]]);
