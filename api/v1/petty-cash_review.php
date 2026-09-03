<?php
/**
 * POST /api/v1/petty-cash/{id}/review — pending -> reviewed.
 * Mirrors api/review_petty_cash.php.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_petty_cash.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'petty_cash');
vk_api_require_permission($auth, 'review', 'petty_cash');

$result = vk_api_petty_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'reviewed',
    ['pending'],
    'reviewed',
    'marked as reviewed'
);

$row = vk_api_petty_row($result['row']);
$row['actions'] = vk_api_petty_actions($auth, $row['status']);

vk_api_ok([
    'voucher' => $row,
    'message' => 'Voucher marked as reviewed.',
]);
