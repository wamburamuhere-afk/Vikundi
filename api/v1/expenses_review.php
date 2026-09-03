<?php
/**
 * POST /api/v1/expenses/{id}/review — pending -> reviewed.
 *
 * Step one of the group's three-approval rule. Mirrors api/review_general_expense.php.
 * The view check accompanies the review check because core/permissions.php's
 * canReview() is `isAdmin() || (canView() && review)`.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_expenses.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'expenses');
vk_api_require_permission($auth, 'review', 'expenses');

$result = vk_api_expenses_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'reviewed',
    ['pending'],
    'reviewed',
    'marked as reviewed'
);

$row = vk_api_expenses_row($result['row']);
$row['actions'] = vk_api_expenses_actions($auth, $row['status']);

vk_api_ok([
    'expense' => $row,
    'message' => 'Expense marked as reviewed.',
]);
