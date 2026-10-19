<?php
// actions/loan_action.php — every state change on a loan.
//
//   apply     a member applies (leadership may apply on a member's behalf)
//   respond   a guarantor accepts or declines
//   cancel    the borrower withdraws an undecided application
//   approve   leadership approves            (approve_loan)
//   reject    leadership rejects, with reason (approve_loan)
//   disburse  the money is paid out           (disburse_loan)
//   repay     a repayment is received         (disburse_loan)
//
// Replaces apply_for_loan.php / approve_loan_vicoba.php / record_loan_payment.php, which checked only that
// SOMEONE was signed in, took customer_id and the interest rate from the request,
// and had no CSRF protection. Here the caller's identity decides whose loan it
// is, the rules come from the loan type, and each step is authorised on its own.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/member_savings.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/activity_logger.php';
global $pdo;

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$fail = static function (string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
};
$int = static fn(string $key): int => isset($_POST[$key]) && ctype_digit((string) $_POST[$key]) ? (int) $_POST[$key] : 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fail($is_sw ? 'Ombi si sahihi.' : 'Invalid request.', 405);
}

$uid     = (int) $_SESSION['user_id'];
$me      = vk_member_customer_id($pdo, $uid);   // null for an account with no member record
$action  = (string) ($_POST['action'] ?? '');
$loan_id = $int('loan_id');
$denied  = $is_sw ? 'Huna ruhusa ya kufanya kitendo hiki.' : 'You do not have permission to perform this action.';
$no_member = $is_sw ? 'Akaunti yako haina wasifu wa mwanachama.' : 'Your account has no member profile.';

try {
    switch ($action) {
        case 'apply':
            // Leadership may file an application for any member; anyone else only
            // for themselves, whatever customer_id the request carries.
            $customer_id = (vl_can_manage() && $int('customer_id') > 0) ? $int('customer_id') : (int) ($me ?? 0);
            if ($customer_id <= 0) {
                $fail($no_member, 403);
            }
            $amount = str_replace([',', ' '], '', (string) ($_POST['amount'] ?? ''));
            $res = vl_apply(
                $pdo, $customer_id, $int('loan_type_id'), is_numeric($amount) ? (float) $amount : 0.0, $int('term_months'),
                (string) ($_POST['purpose'] ?? ''), (array) ($_POST['guarantors'] ?? []), $uid, $is_sw
            );
            if ($res['ok']) {
                logCreate('Loans', $res['reference'] . ' — TZS ' . number_format((float) $amount, 2), 'LOAN#' . $res['loan_id']);
            }
            break;

        case 'respond':
            if ($me === null) {
                $fail($no_member, 403);
            }
            $decision = (string) ($_POST['decision'] ?? '');
            if (!in_array($decision, ['accept', 'decline'], true)) {
                $fail($is_sw ? 'Chagua kukubali au kukataa.' : 'Choose accept or decline.');
            }
            // Keyed on the caller's own member id: nobody can answer for another guarantor.
            $res = vl_respond_guarantee($pdo, $loan_id, $me, $decision === 'accept', (string) ($_POST['note'] ?? ''), $is_sw);
            if ($res['ok']) {
                logUpdate('Loans', 'Guarantee ' . ($decision === 'accept' ? 'accepted' : 'declined'), "LOAN#$loan_id");
            }
            break;

        case 'respond_for':
            // A leader answers for a guarantor who does not sign in, saying how they agreed.
            if (!canApprove('approve_loan')) {
                $fail($denied, 403);
            }
            $decision = (string) ($_POST['decision'] ?? '');
            if (!in_array($decision, ['accept', 'decline'], true)) {
                $fail($is_sw ? 'Chagua kukubali au kukataa.' : 'Choose accept or decline.');
            }
            $leader = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) FROM users WHERE user_id = ?");
            $leader->execute([$uid]);
            $gid = $int('guarantor_customer_id');
            $res = vl_record_guarantee_for($pdo, $loan_id, $gid, $decision === 'accept', (string) ($_POST['note'] ?? ''), $uid,
                                           (string) ($leader->fetchColumn() ?: ('user #' . $uid)), $is_sw);
            if ($res['ok']) {
                logUpdate('Loans', 'Guarantee ' . ($decision === 'accept' ? 'accepted' : 'declined') . " for guarantor #$gid, recorded by a leader", "LOAN#$loan_id");
            }
            break;

        case 'cancel':
            if ($me === null) {
                $fail($no_member, 403);
            }
            $res = vl_cancel($pdo, $loan_id, $me, $is_sw);
            if ($res['ok']) {
                logUpdate('Loans', 'Application withdrawn', "LOAN#$loan_id");
            }
            break;

        case 'approve':
        case 'reject':
            if (!canApprove('approve_loan')) {
                $fail($denied, 403);
            }
            $res = vl_decide($pdo, $loan_id, $action === 'approve', $uid, (string) ($_POST['reason'] ?? ''), $is_sw);
            if ($res['ok']) {
                logUpdate('Loans', $action === 'approve' ? 'Approved' : 'Rejected', "LOAN#$loan_id");
            }
            break;

        case 'disburse':
            if (!canCreate('disburse_loan')) {
                $fail($denied, 403);
            }
            $res = vl_disburse(
                $pdo, $loan_id, $uid, (string) ($_POST['account'] ?? ''), (string) ($_POST['reference'] ?? ''),
                trim((string) ($_POST['disbursement_date'] ?? '')) ?: date('Y-m-d'), $is_sw
            );
            if ($res['ok']) {
                logUpdate('Loans', 'Disbursed', "LOAN#$loan_id");
            }
            break;

        case 'repay':
            // Receiving loan money is the same trust as paying it out.
            if (!canCreate('disburse_loan')) {
                $fail($denied, 403);
            }
            $paid = str_replace([',', ' '], '', (string) ($_POST['amount'] ?? ''));
            $res = vl_record_payment(
                $pdo, $loan_id, is_numeric($paid) ? (float) $paid : 0.0,
                trim((string) ($_POST['payment_date'] ?? '')) ?: date('Y-m-d'), (string) ($_POST['account'] ?? ''),
                (string) ($_POST['receipt_number'] ?? ''), (string) ($_POST['note'] ?? ''), $uid, $is_sw
            );
            if ($res['ok']) {
                logCreate('Loan Repayments', 'TZS ' . number_format((float) $paid, 2), "LOAN#$loan_id");
            }
            break;

        default:
            $fail($is_sw ? 'Ombi si sahihi.' : 'Invalid request.', 400);
    }

    echo json_encode(['success' => $res['ok'], 'message' => $res['message'], 'loan_id' => $res['loan_id'] ?? $loan_id]);
} catch (Throwable $e) {
    error_log('loan_action(' . $action . '): ' . $e->getMessage());
    $fail($is_sw ? 'Imeshindikana. Jaribu tena.' : 'Something went wrong. Please try again.', 500);
}
