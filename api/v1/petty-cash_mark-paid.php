<?php
/**
 * POST /api/v1/petty-cash/{id}/mark-paid — approved -> paid.
 *
 * Mirrors actions/mark_expense_paid.php (type=petty) exactly, including who
 * may do it: canMarkPaid() — the Treasurer or a full admin — not a
 * role_permissions grant. No e-signature is captured for this step.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_petty_cash.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

if (!vk_api_petty_may_mark_paid($auth)) {
    vk_api_error(403, 'forbidden', 'Only the Treasurer or an administrator can mark a voucher as paid.');
}

$result = vk_api_petty_mark_paid($pdo, $auth, (int) ($_GET['id'] ?? 0));

$row = vk_api_petty_row($result['row']);
$row['actions'] = vk_api_petty_actions($auth, $row['status']);

vk_api_ok([
    'voucher' => $row,
    'message' => 'Voucher marked as paid.',
]);
