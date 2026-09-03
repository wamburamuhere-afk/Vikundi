<?php
/**
 * POST /api/v1/petty-cash/{id}/approve — reviewed -> approved.
 *
 * Mirrors actions/approve_petty_cash.php. NO fund-balance gate — unlike
 * Expenses/Condolences, the web's own approve action for petty cash has
 * never had one, so this does not add a check the web has never enforced.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_petty_cash.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'petty_cash');
vk_api_require_permission($auth, 'approve', 'petty_cash');

$result = vk_api_petty_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'approved',
    ['reviewed'],
    'approved',
    'approved'
);

$row = vk_api_petty_row($result['row']);
$row['actions'] = vk_api_petty_actions($auth, $row['status']);

vk_api_ok([
    'voucher' => $row,
    'message' => 'Voucher approved.',
]);
