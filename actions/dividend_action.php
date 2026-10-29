<?php
// actions/dividend_action.php — every state change on a dividend run.
//
//   confirm     freeze a period's figures as a run   (dividends: create)
//   distribute  pay a confirmed run out              (dividends: approve)
//   cancel      drop a confirmed, undistributed run  (dividends: delete)
//
// The figures are never taken from the request: only the period and the payment
// method are, and the run is recalculated server-side from the books.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/dividends.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/features.php';
global $pdo;

vk_require_feature('dividends', true);

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$fail = static function (string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fail($is_sw ? 'Ombi si sahihi.' : 'Invalid request.', 405);
}

$uid    = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');
$run_id = isset($_POST['run_id']) && ctype_digit((string) $_POST['run_id']) ? (int) $_POST['run_id'] : 0;
$denied = $is_sw ? 'Huna ruhusa ya kufanya kitendo hiki.' : 'You do not have permission to perform this action.';

try {
    switch ($action) {
        case 'confirm':
            if (!canCreate('dividends')) {
                $fail($denied, 403);
            }
            $res = dv_confirm($pdo, trim((string) ($_POST['period_from'] ?? '')), trim((string) ($_POST['period_to'] ?? '')), (string) ($_POST['method'] ?? ''), $uid, $is_sw);
            if ($res['ok']) {
                $run_id = (int) $res['run_id'];
                logCreate('Dividends', $res['reference'], "DIVIDEND#$run_id");
            }
            break;

        case 'distribute':
            if (!canApprove('dividends')) {
                $fail($denied, 403);
            }
            $res = dv_distribute($pdo, $run_id, $uid, $is_sw);
            if ($res['ok']) {
                logUpdate('Dividends', 'Distributed', "DIVIDEND#$run_id");
            }
            break;

        case 'cancel':
            if (!canDelete('dividends')) {
                $fail($denied, 403);
            }
            $res = dv_cancel($pdo, $run_id, $is_sw);
            if ($res['ok']) {
                logUpdate('Dividends', 'Cancelled', "DIVIDEND#$run_id");
            }
            break;

        default:
            $fail($is_sw ? 'Ombi si sahihi.' : 'Invalid request.', 400);
    }

    echo json_encode(['success' => $res['ok'], 'message' => $res['message'], 'run_id' => $run_id]);
} catch (Throwable $e) {
    error_log('dividend_action(' . $action . '): ' . $e->getMessage());
    $fail($is_sw ? 'Imeshindikana. Jaribu tena.' : 'Something went wrong. Please try again.', 500);
}
