<?php
/**
 * POST /api/v1/budgets/{id}/approve — reviewed -> approved.
 *
 * Mirrors api/account/approve_budget.php. NO fund-balance gate, unlike
 * Expenses — a budget is a plan, not a disbursement, and the web's own
 * approve action has never checked the group's fund balance.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_budgets.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'budget');
vk_api_require_permission($auth, 'approve', 'budget');

$result = vk_api_budgets_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'approved',
    ['reviewed'],
    'approved',
    'approved'
);

$row = vk_api_budgets_row($result['row'], $result['items']);
$row['actions'] = vk_api_budgets_actions($auth, $row['status']);

vk_api_ok([
    'budget'  => $row,
    'message' => 'Budget approved.',
]);
