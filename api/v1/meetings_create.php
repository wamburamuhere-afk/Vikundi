<?php
/**
 * POST /api/v1/meetings — schedule a meeting.
 *
 * Reached through meetings.php, which has already authenticated. Also usable
 * directly. Mirrors actions/save_meeting.php's create path, reusing its own
 * validation (includes/meeting_helpers.php) so the two cannot disagree about
 * what a valid meeting looks like.
 *
 * No attachments — same call as every create-with-uploads module in this
 * API (Condolences, Expenses): the web files a document into the shared
 * library, a subsystem this endpoint does not touch.
 *
 * `create` on `meetings`.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_meetings.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}

vk_api_require_permission($auth, 'create', 'meetings');

$body = vk_api_body();

$errors = vk_meeting_input_errors($body);
if ($errors) {
    vk_api_error(422, 'invalid_meeting', implode(' ', $errors));
}

$title    = trim((string) $body['title']);
$date     = trim((string) $body['meeting_date']);
$time     = trim((string) ($body['meeting_time'] ?? '')) ?: null;
$location = trim((string) ($body['location'] ?? '')) ?: null;
$type     = vk_normalize_meeting_type($body['meeting_type'] ?? 'regular');
$agenda   = trim((string) ($body['agenda'] ?? '')) ?: null;
$minutes  = trim((string) ($body['minutes'] ?? '')) ?: null;
$status   = vk_normalize_meeting_status($body['status'] ?? 'scheduled');

$st = $pdo->prepare(
    'INSERT INTO meetings (title, meeting_date, meeting_time, location, meeting_type, agenda, minutes, status, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$st->execute([$title, $date, $time, $location, $type, $agenda, $minutes, $status, (int) $auth['user_id']]);
$newId = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = (int) $auth['user_id']; // logCreate() reads the session
logCreate('Meetings', $title, 'MEETING#' . $newId, (int) $auth['user_id']);

$row = vk_api_meetings_row(vk_api_meetings_load($pdo, $newId));
$row['actions'] = vk_api_meetings_actions($auth);

vk_api_ok([
    'meeting' => $row,
    'message' => 'Meeting saved.',
], 201);
