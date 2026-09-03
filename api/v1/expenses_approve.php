<?php
/**
 * POST /api/v1/expenses/{id}/approve — reviewed -> approved.
 *
 * Mirrors api/approve_general_expense.php, including its fund-balance check:
 * approving authorises the spend, so it must not authorise more than the
 * group's real, computed available fund (includes/finance.php). The balance
 * itself only drops once the expense is marked paid (cash basis) — approve
 * does not move money, it clears it to be moved.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_expenses.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'expenses');
vk_api_require_permission($auth, 'approve', 'expenses');

$result = vk_api_expenses_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'approved',
    ['reviewed'],
    'approved',
    'approved'
);

$row = vk_api_expenses_row($result['row']);
$row['actions'] = vk_api_expenses_actions($auth, $row['status']);

vk_api_ok([
    'expense' => $row,
    'message' => 'Expense approved. The balance will update once it is marked paid.',
]);
