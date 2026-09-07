<?php
/**
 * GET /api/v1/voting/open — elections currently open that THIS member is
 * eligible to vote in. Mirrors app/constant/voting/voting.php's "open votes"
 * section: scoped by vote_eligibility (the snapshot frozen when the election
 * opened), never a plain "every open election" list.
 *
 * Gated on `voting` — the personal-ballot page permission (not
 * `manage_voting`), so a leader who is a member votes through the same door
 * as everyone else.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'voting');

$memberId = vk_api_member_id((int) $auth['user_id']);
if ($memberId <= 0) {
    vk_api_ok(['elections' => []]);
}

$pdo->exec("UPDATE votes SET status='closed' WHERE status='open' AND closes_at IS NOT NULL AND closes_at < NOW()");

$st = $pdo->prepare("
    SELECT v.* FROM votes v
      JOIN vote_eligibility e ON e.vote_id = v.id AND e.member_id = ?
     WHERE v.status = 'open'
     ORDER BY v.created_at DESC
");
$st->execute([$memberId]);
$open = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$open) {
    vk_api_ok(['elections' => []]);
}

$ids = array_map(static fn(array $v): int => (int) $v['id'], $open);
$in  = implode(',', array_fill(0, count($ids), '?'));

$optSt = $pdo->prepare("SELECT vote_id, id, label, member_id, position FROM vote_options WHERE vote_id IN ({$in}) ORDER BY vote_id, position, id");
$optSt->execute($ids);
$optsByVote = [];
foreach ($optSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $optsByVote[(int) $r['vote_id']][] = $r;
}

$votedSt = $pdo->prepare("SELECT DISTINCT vote_id FROM vote_participation WHERE member_id = ? AND vote_id IN ({$in})");
$votedSt->execute(array_merge([$memberId], $ids));
$votedSet = array_map('intval', $votedSt->fetchAll(PDO::FETCH_COLUMN));

vk_api_ok([
    'elections' => array_map(
        static fn(array $v): array => vk_api_voting_open_row(
            $v,
            $optsByVote[(int) $v['id']] ?? [],
            in_array((int) $v['id'], $votedSet, true)
        ),
        $open
    ),
]);
