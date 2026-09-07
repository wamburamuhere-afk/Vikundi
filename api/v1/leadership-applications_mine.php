<?php
/**
 * GET /api/v1/leadership-applications/mine — the caller's own application(s),
 * across every election (the UNIQUE key is per-election, so a member who has
 * stood more than once has more than one row).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'leadership_applications');

$memberId = vk_api_member_id((int) $auth['user_id']);
if ($memberId <= 0) {
    vk_api_ok(['applications' => []]);
}

$st = $pdo->prepare("
    SELECT a.*, v.status AS election_status, v.title AS election_title,
           TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS proposer_name
      FROM leadership_applications a
      JOIN votes v ON v.id = a.vote_id
      LEFT JOIN customers p ON p.customer_id = a.proposer_member_id
     WHERE a.member_id = ?
     ORDER BY a.created_at DESC
");
$st->execute([$memberId]);
$apps = $st->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok([
    'applications' => array_map(static function (array $a) use ($auth): array {
        $row = vk_api_application_row($a);
        $row['actions'] = vk_api_application_actions($auth, true, $a, (string) $a['election_status']);
        return $row;
    }, $apps),
]);
