<?php
/**
 * POST /api/v1/budgets/{id}/review — pending -> reviewed.
 * Mirrors api/account/review_budget.php.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_budgets.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'budget');
vk_api_require_permission($auth, 'review', 'budget');

$result = vk_api_budgets_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'reviewed',
    ['pending'],
    'reviewed',
    'marked as reviewed'
);

$row = vk_api_budgets_row($result['row'], $result['items']);
$row['actions'] = vk_api_budgets_actions($auth, $row['status']);

vk_api_ok([
    'budget'  => $row,
    'message' => 'Budget marked as reviewed.',
]);
