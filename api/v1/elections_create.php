<?php
/**
 * POST /api/v1/elections — create a draft election.
 *
 * Reached through elections.php, which has already authenticated. Mirrors
 * actions/save_vote.php's create path exactly (this endpoint does not cover
 * editing a draft — the web's own edit path DELETEs and re-inserts every
 * option, which would silently orphan any leadership_applications row already
 * linked to one via vote_option_id; not worth the risk for a feature todo.md
 * never asked for. Use POST .../{id}/open once options are right, or delete
 * and recreate a draft that never received applications).
 *
 * A candidate election may be created with ZERO options — deliberate, see
 * vk_vote_input_errors()'s own doc comment: a leadership election is meant to
 * start empty and be filled by member applications, reviewed by the
 * Committee. A motion election always gets the fixed Yes/No/Abstain set.
 */

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}
vk_api_require_permission($auth, 'create', 'manage_voting');

$body   = vk_api_body();
$type   = vk_normalize_vote_type($body['vote_type'] ?? 'candidate');
$labels = array_map('strval', (array) ($body['option_labels'] ?? []));

$errors = vk_vote_input_errors($body, $labels);
if ($errors) {
    vk_api_error(422, 'invalid_election', implode(' ', $errors));
}

$title       = trim((string) $body['title']);
$description = trim((string) ($body['description'] ?? '')) ?: null;
$closesAt    = trim((string) ($body['closes_at'] ?? ''));
$closesAt    = $closesAt !== '' ? date('Y-m-d H:i:s', strtotime($closesAt)) : null;
$publish     = !empty($body['publish_results']) ? 1 : 0;

$options = vk_api_election_build_options($type, $labels, (array) ($body['option_member_ids'] ?? []));

$pdo->prepare(
    "INSERT INTO votes (title, description, vote_type, status, closes_at, publish_results, created_by)
     VALUES (?, ?, ?, 'draft', ?, ?, ?)"
)->execute([$title, $description, $type, $closesAt, $publish, (int) $auth['user_id']]);
$id = (int) $pdo->lastInsertId();

$ins = $pdo->prepare('INSERT INTO vote_options (vote_id, label, member_id, position) VALUES (?, ?, ?, ?)');
foreach ($options as $pos => [$label, $memberId]) {
    $ins->execute([$id, $label, $memberId, $pos]);
}

$_SESSION['user_id'] = (int) $auth['user_id'];
logCreate('Voting', $title, 'VOTE#' . $id, (int) $auth['user_id']);

$created = vk_api_election_load($pdo, $id);
$row = vk_api_election_row($created);
$row['actions'] = vk_api_election_actions($auth, $created['status']);
$row['options'] = array_map('vk_api_election_option_row', vk_api_election_options($pdo, $id));

vk_api_ok(['election' => $row, 'message' => 'Election created.'], 201);
