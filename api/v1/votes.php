<?php
/**
 * POST /api/v1/votes — cast a secret ballot. Mirrors actions/cast_vote.php.
 *
 * Body: { "election_id": int, "option_id": int } (also accepts "vote_id" —
 * the web form's own field name — as an alias, so a client that copies the
 * web's naming still works).
 *
 * Gated on `voting`, not `manage_voting` — this is a member casting their own
 * ballot. The election-status, eligibility, option-ownership and
 * second-vote-refusal rules all live in vk_api_cast_vote(), identical to the
 * web action.
 *
 * NO ACTIVITY LOG ENTRY — deliberately, matching actions/cast_vote.php's own
 * header comment exactly: the whole point of the secret-ballot design
 * (vote_participation records THAT a member voted; the anonymous
 * vote_ballots records the CHOICE; the two are never joined) is undermined by
 * an audit-trail row timestamping "member X voted in election Y," so the web
 * action calls no log function at all. This endpoint doesn't either.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'voting');

$memberId = vk_api_member_id((int) $auth['user_id']);
if ($memberId <= 0) {
    vk_api_error(403, 'no_member_record', 'This account has no member record, so it cannot vote.');
}

$body     = vk_api_body();
$electionId = (int) ($body['election_id'] ?? $body['vote_id'] ?? 0);
$optionId   = (int) ($body['option_id'] ?? 0);

if ($electionId <= 0 || $optionId <= 0) {
    vk_api_error(422, 'invalid_request', 'election_id and option_id are required.');
}

vk_api_cast_vote($pdo, $electionId, $memberId, $optionId);

vk_api_ok(['message' => 'Your vote has been recorded. Thank you!'], 201);
