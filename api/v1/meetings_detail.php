<?php
/**
 * GET    /api/v1/meetings/{id} — one meeting, with the full attendance roster
 * PUT    /api/v1/meetings/{id} — edit
 * DELETE /api/v1/meetings/{id} — delete (cascades attendance)
 *
 * No status guard on edit — the web has never had one for meetings (unlike
 * Budgets), so this doesn't add one.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_meetings.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT', 'DELETE']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    vk_api_require_permission($auth, 'delete', 'meetings');

    $loaded = vk_api_meetings_load($pdo, $id);

    $pdo->prepare('DELETE FROM meeting_attendance WHERE meeting_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM meetings WHERE id = ?')->execute([$id]);

    $_SESSION['user_id'] = (int) $auth['user_id'];
    logDelete('Meetings', $loaded['title'], 'MEETING#' . $id, (int) $auth['user_id']);

    vk_api_ok(['message' => 'Meeting deleted.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    vk_api_require_permission($auth, 'edit', 'meetings');

    $current = vk_api_meetings_load($pdo, $id); // 404s if missing

    $body = vk_api_body();
    $sets = [];
    $vals = [];

    if (array_key_exists('title', $body) || array_key_exists('meeting_date', $body)) {
        // Both are required fields on the row — validate together, same rule
        // as the web's vk_meeting_input_errors(), using whichever value is
        // being kept vs. changed.
        $merged = [
            'title'        => array_key_exists('title', $body) ? $body['title'] : $current['title'],
            'meeting_date' => array_key_exists('meeting_date', $body) ? $body['meeting_date'] : $current['meeting_date'],
        ];
        $errors = vk_meeting_input_errors($merged);
        if ($errors) {
            vk_api_error(422, 'invalid_meeting', implode(' ', $errors));
        }
        $sets[] = 'title = ?';
        $vals[] = trim((string) $merged['title']);
        $sets[] = 'meeting_date = ?';
        $vals[] = trim((string) $merged['meeting_date']);
    }
    if (array_key_exists('meeting_time', $body)) {
        $sets[] = 'meeting_time = ?';
        $vals[] = trim((string) $body['meeting_time']) ?: null;
    }
    if (array_key_exists('location', $body)) {
        $sets[] = 'location = ?';
        $vals[] = trim((string) $body['location']) ?: null;
    }
    if (array_key_exists('meeting_type', $body)) {
        $sets[] = 'meeting_type = ?';
        $vals[] = vk_normalize_meeting_type($body['meeting_type']);
    }
    if (array_key_exists('agenda', $body)) {
        $sets[] = 'agenda = ?';
        $vals[] = trim((string) $body['agenda']) ?: null;
    }
    if (array_key_exists('minutes', $body)) {
        $sets[] = 'minutes = ?';
        $vals[] = trim((string) $body['minutes']) ?: null;
    }
    if (array_key_exists('status', $body)) {
        $sets[] = 'status = ?';
        $vals[] = vk_normalize_meeting_status($body['status']);
    }

    if (!$sets) {
        vk_api_error(422, 'no_fields', 'Nothing to update — send at least one editable field.');
    }

    $vals[] = $id;
    $pdo->prepare('UPDATE meetings SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

    $_SESSION['user_id'] = (int) $auth['user_id'];
    $updated = vk_api_meetings_load($pdo, $id);
    logUpdate('Meetings', $updated['title'], 'MEETING#' . $id, (int) $auth['user_id']);

    $row = vk_api_meetings_row($updated);
    $row['actions'] = vk_api_meetings_actions($auth);

    vk_api_ok(['meeting' => $row, 'message' => 'Meeting saved.']);
}

vk_api_require_permission($auth, 'view', 'meetings');

$loaded = vk_api_meetings_load($pdo, $id);
$meeting = vk_api_meetings_row($loaded);
$meeting['actions'] = vk_api_meetings_actions($auth);

$roster = vk_api_meetings_roster($pdo, $id);

vk_api_ok([
    'meeting'    => $meeting,
    'attendance' => array_map('vk_api_meetings_roster_row', $roster),
    'summary'    => vk_attendance_summary(array_map(static fn(array $r): array => ['status' => $r['att_status']], $roster)),
]);
