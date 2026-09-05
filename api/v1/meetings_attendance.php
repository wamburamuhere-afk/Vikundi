<?php
/**
 * POST /api/v1/meetings/{id}/attendance — record present/absent for members.
 *
 * `attendance` is `[{member_id, status}]` — only the rows sent are written.
 * See includes/api_meetings.php's own note: unlike
 * actions/save_meeting_attendance.php, this does NOT resubmit-and-reset the
 * whole roster — a member left out simply keeps their existing status.
 *
 * `edit` on `meetings`, same gate the web action uses.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_meetings.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'edit', 'meetings');

$id = (int) ($_GET['id'] ?? 0);
vk_api_meetings_load($pdo, $id); // 404s if missing

$body    = vk_api_body();
$entries = vk_api_meetings_parse_attendance($pdo, $body['attendance'] ?? null);

$_SESSION['user_id'] = (int) $auth['user_id'];
$count = vk_api_meetings_save_attendance($pdo, $id, $entries, (int) $auth['user_id']);

logUpdate('Meetings', "Attendance ($count)", 'MEETING#' . $id, (int) $auth['user_id']);

$roster = vk_api_meetings_roster($pdo, $id);

vk_api_ok([
    'attendance' => array_map('vk_api_meetings_roster_row', $roster),
    'summary'    => vk_attendance_summary(array_map(static fn(array $r): array => ['status' => $r['att_status']], $roster)),
    'message'    => 'Attendance saved.',
]);
