<?php
/**
 * GET /api/v1/fines/{id} — one fine
 * PUT /api/v1/fines/{id} — edit it (delegates to fines_update.php)
 *
 * READ IS NOT LEADERSHIP-ONLY, deliberately. my_fines.php already shows any
 * member every fine in the group through its ?view=all toggle — a decision the
 * group took, mirroring the Group Financial Ledger. Refusing a member one row
 * they can already see in a list would be theatre, not access control.
 *
 * Writing is another matter and is gated in fines_update.php.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_fines.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    require __DIR__ . '/fines_update.php';
    exit;
}

$row = vk_api_fine_load($pdo, (int) ($_GET['id'] ?? 0));

$fine = vk_api_fine_row($row, vk_api_member_id((int) $auth['user_id']));
$fine['actions'] = vk_api_fine_actions($auth, $fine['status']);

vk_api_ok(['fine' => $fine]);
