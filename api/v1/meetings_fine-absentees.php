<?php
/**
 * POST /api/v1/meetings/{id}/fine-absentees — fine members marked absent.
 *
 * Not in the original plan, but a real, used web action
 * (actions/generate_absence_fines.php, the meeting screen's own "Fine
 * Absentees" button) — added for parity, same reasoning as every prior
 * module's extra endpoints.
 *
 * ONLY FINES MEMBERS WITH A REAL 'absent' ROW in meeting_attendance — not
 * every member the roster *displays* as absent by default. A member never
 * marked at all (no attendance taken yet) is not fined, matching the web
 * exactly: `generate_absence_fines.php` queries
 * `meeting_attendance WHERE status = 'absent'`, which returns nothing for an
 * unmarked meeting.
 *
 * Deduplicates against `fines.meeting_id` — running this twice does not
 * double-fine anyone already fined for this meeting. Remembers `amount` as
 * the group's next default, same as the web.
 *
 * `edit` on `meetings` — leadership only, same gate the web action uses.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_meetings.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'edit', 'meetings');

$id = (int) ($_GET['id'] ?? 0);
$meeting = vk_api_meetings_load($pdo, $id);

$body   = vk_api_body();
$amount = (float) ($body['amount'] ?? 0);
if ($amount <= 0) {
    vk_api_error(422, 'invalid_amount', 'amount is required and must be greater than zero.');
}

$absentees = $pdo->prepare("SELECT member_id FROM meeting_attendance WHERE meeting_id = ? AND status = 'absent'");
$absentees->execute([$id]);
$ids = $absentees->fetchAll(PDO::FETCH_COLUMN);

$reason = vk_meeting_fine_reason($meeting);
$dup = $pdo->prepare('SELECT COUNT(*) FROM fines WHERE customer_id = ? AND meeting_id = ?');
$ins = $pdo->prepare("INSERT INTO fines (customer_id, amount, reason, status, meeting_id, created_at) VALUES (?, ?, ?, 'pending', ?, NOW())");

$created = 0;
$skipped = 0;
foreach ($ids as $memberId) {
    $dup->execute([$memberId, $id]);
    if ((int) $dup->fetchColumn() > 0) {
        $skipped++;
        continue;
    }
    $ins->execute([$memberId, $amount, $reason, $id]);
    $created++;
}

$pdo->prepare(
    "INSERT INTO group_settings (setting_key, setting_value) VALUES ('meeting_absence_fine', :v)
     ON DUPLICATE KEY UPDATE setting_value = :v2"
)->execute([':v' => $amount, ':v2' => $amount]);

$_SESSION['user_id'] = (int) $auth['user_id'];
logCreate('Fines', "Absence fines: $created", 'MEETING#' . $id, (int) $auth['user_id']);

vk_api_ok([
    'created' => $created,
    'skipped' => $skipped,
    'message' => "Created fines for {$created} member(s)." . ($skipped > 0 ? " ({$skipped} already fined.)" : ''),
]);
