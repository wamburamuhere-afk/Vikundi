<?php
/**
 * POST /api/v1/expenses/{id}/mark-paid — approved -> paid.
 *
 * Records that money already authorised actually left the account. Mirrors
 * actions/mark_expense_paid.php (type=general) exactly, including who may do
 * it: canMarkPaid() — the Treasurer or a full admin, "the people who release
 * the group's money" — NOT a role_permissions grant like every other action
 * in this module. No e-signature is captured for this step, matching the web.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_expenses.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

if (!vk_api_expenses_may_mark_paid($auth)) {
    vk_api_error(403, 'forbidden', 'Only the Treasurer or an administrator can mark an expense as paid.');
}

$result = vk_api_expenses_mark_paid($pdo, $auth, (int) ($_GET['id'] ?? 0));

$row = vk_api_expenses_row($result['row']);
$row['actions'] = vk_api_expenses_actions($auth, $row['status']);

vk_api_ok([
    'expense' => $row,
    'message' => 'Expense marked as paid.',
]);
