<?php
/**
 * POST /api/v1/leadership-applications — apply (or re-apply) to stand for a
 * leadership position.
 *
 * Reached through leadership-applications.php, which has already
 * authenticated. Mirrors actions/save_leadership_application.php's
 * apply/re-apply path — including its forgiving semantics: if the caller
 * already has a 'pending' or 'withdrawn' application for this election, this
 * UPDATES that same row rather than refusing a duplicate, exactly like the
 * web (re-clicking "Apply" after withdrawing just works). A ruled-on
 * application ('approved'/'rejected') is final — see
 * PUT .../{id} for editing a known application by id instead.
 */

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}
vk_api_require_permission($auth, 'create', 'leadership_applications');

$memberId = vk_api_member_id((int) $auth['user_id']);
if ($memberId <= 0) {
    vk_api_error(403, 'no_member_record', 'This account has no member record, so it cannot apply.');
}

$body      = vk_api_body();
$electionId = (int) ($body['election_id'] ?? $body['vote_id'] ?? 0);
if ($electionId <= 0) {
    vk_api_error(422, 'invalid_request', 'election_id is required.');
}

$electionSt = $pdo->prepare("SELECT id, title, status, vote_type FROM votes WHERE id = ? LIMIT 1");
$electionSt->execute([$electionId]);
$election = $electionSt->fetch(PDO::FETCH_ASSOC);
if (!$election || $election['vote_type'] !== 'candidate') {
    vk_api_error(404, 'not_found', 'No election was found with that id.');
}
if ($election['status'] !== 'draft') {
    vk_api_error(409, 'applications_closed', 'Applications are closed for this election.');
}

$existing = vk_member_application($pdo, $electionId, $memberId);
if ($existing && in_array($existing['status'], ['approved', 'rejected'], true)) {
    vk_api_error(409, 'already_reviewed', 'Your application has already been reviewed by the Committee.');
}

$positions = vk_leadership_positions($pdo);
$errors = vk_api_application_input_errors($body, $positions);
if ($errors) {
    vk_api_error(422, 'invalid_application', implode(' ', $errors));
}

$position   = trim((string) $body['position']);
$statement  = trim((string) $body['statement']);
$experience = trim((string) ($body['experience'] ?? ''));
$proposer   = (int) ($body['proposer_member_id'] ?? 0) ?: null;

if ($proposer !== null) {
    if ($proposer === $memberId) {
        vk_api_error(422, 'invalid_proposer', 'You cannot propose yourself.');
    }
    $pc = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE customer_id = ? AND status <> 'deleted'");
    $pc->execute([$proposer]);
    if ((int) $pc->fetchColumn() === 0) {
        vk_api_error(404, 'proposer_not_found', 'No member was found with that proposer id.');
    }
}

try {
    if ($existing) {
        $pdo->prepare("
            UPDATE leadership_applications
               SET position = ?, statement = ?, experience = ?, proposer_member_id = ?,
                   declaration = 1, status = 'pending', review_note = NULL,
                   reviewed_by = NULL, reviewed_at = NULL
             WHERE id = ?
        ")->execute([$position, $statement, $experience, $proposer, $existing['id']]);
        $appId = (int) $existing['id'];
        $_SESSION['user_id'] = (int) $auth['user_id'];
        logUpdate('Leadership Applications', $position, 'LA#' . $appId, (int) $auth['user_id']);
    } else {
        $pdo->prepare("
            INSERT INTO leadership_applications
                (vote_id, member_id, position, statement, experience, proposer_member_id, declaration)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ")->execute([$electionId, $memberId, $position, $statement, $experience, $proposer]);
        $appId = (int) $pdo->lastInsertId();
        $_SESSION['user_id'] = (int) $auth['user_id'];
        logCreate('Leadership Applications', $position, 'LA#' . $appId, (int) $auth['user_id']);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        vk_api_error(409, 'already_applied', 'You already have an application for this election.');
    }
    throw $e;
}

$saved = vk_api_application_load($pdo, $appId);
$row = vk_api_application_row($saved);
$row['actions'] = vk_api_application_actions($auth, true, $saved, (string) $saved['election_status']);

vk_api_ok([
    'application' => $row,
    'message'     => 'Your application has been submitted. The Committee will review it.',
], $existing ? 200 : 201);
