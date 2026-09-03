<?php
/**
 * GET /api/v1/my/mkoba-reconciliation — one member's own M-Koba tie-out: is
 * every M-Koba transaction they made reflected in Vikundi, for the same
 * amount?
 *
 * Mirrors app/constant/reports/member_mkoba_reconciliation.php (see
 * includes/api_mkoba_reconciliation.php). Scoped to the token by default.
 * Leadership may pass ?member_id= to check someone else's — the same
 * override the web page allows via ?id=, gated the same way:
 * isAdmin() || canCreate('manage_contributions'), which is deliberately a
 * different key from the group-wide `mkoba_reconciliation` gate.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_mkoba_reconciliation.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
$own  = vk_api_member_id((int) $auth['user_id']);

$requested = (int) ($_GET['member_id'] ?? 0);
$memberId  = ($requested > 0 && vk_api_mkoba_may_override($auth)) ? $requested : $own;

if ($memberId <= 0) {
    vk_api_error(
        403,
        'no_member_record',
        'This account has no member record, so it has no M-Koba reconciliation of its own.'
    );
}

$st = $pdo->prepare('SELECT customer_id, first_name, middle_name, last_name FROM customers WHERE customer_id = ?');
$st->execute([$memberId]);
$member = $st->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    vk_api_error(404, 'not_found', 'No member was found with that id.');
}

$rows    = vk_api_mkoba_member_rows($pdo, $memberId);
$summary = vk_api_mkoba_member_summary($rows);

vk_api_ok([
    'member' => [
        'id'   => $memberId,
        'name' => trim(implode(' ', array_filter([
            $member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? '',
        ]))),
    ],
    'summary' => $summary,
    'rows'    => array_map('vk_api_mkoba_member_row', $rows),
]);
