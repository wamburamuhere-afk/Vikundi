<?php
/**
 * GET /api/v1/mkoba-reconciliation — the group-wide M-Koba statement,
 * mirrored row-for-row and tied out against the ledger.
 *
 * Mirrors app/constant/accounts/mkoba_reconciliation.php (see
 * includes/api_mkoba_reconciliation.php). LEADERSHIP ONLY
 * (`mkoba_reconciliation`) — a member's own tie-out is
 * GET /api/v1/my/mkoba-reconciliation.
 *
 * Query params: batch (defaults to the most recently imported statement),
 * page, per_page.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_mkoba_reconciliation.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_mkoba_require_leader($auth);

$batches  = vk_api_mkoba_batches($pdo);
$selected = trim((string) ($_GET['batch'] ?? ''));
if ($selected === '') {
    $selected = $batches[0]['batch'] ?? '';
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(200, (int) ($_GET['per_page'] ?? 50)));

if ($selected !== '') {
    $summary = vk_api_mkoba_summary($pdo, $selected);
    [$rows, $total] = vk_api_mkoba_rows($pdo, $selected, $page, $perPage);
} else {
    $summary = vk_api_mkoba_empty_summary();
    $rows = [];
    $total = 0;
}

vk_api_ok([
    'batches' => $batches,
    'batch'   => $selected,
    'summary' => $summary,
    'rows'    => array_map('vk_api_mkoba_row', $rows),
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => (($page - 1) * $perPage + count($rows)) < $total,
    ],
]);
