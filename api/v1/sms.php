<?php
/**
 * GET  /api/v1/sms — the group's outbound SMS log, paginated — LEADERSHIP ONLY
 * POST /api/v1/sms — send an SMS to one or more phone numbers
 *
 * Mirrors app/constant/communication/sms_center.php / api/sms_center.php.
 *
 * GET is a hard 403 for anyone who cannot also send (`create` on
 * `message_center`) — tighter than the web's own `?action=list`, which is
 * gated on `view` alone and so hands the whole group's phone numbers and
 * message text to every Member by default. See
 * includes/api_communication.php's header for why this was narrowed rather
 * than mirrored.
 *
 * Sends via includes/sms_helper.php — the real gateway integration (Beem,
 * Africa's Talking, Twilio, or a custom HTTP endpoint), same as the web.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_communication.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();
$callerId = (int) $auth['user_id'];

vk_api_comm_require_leader($auth, 'SMS is available to leadership only.');

sms_ensure_logs_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = vk_api_body();

    $recipients = vk_api_comm_parse_phone_recipients($body['recipients'] ?? '');
    if (!$recipients) {
        vk_api_error(422, 'recipients_required', 'Enter at least one valid phone number in recipients.');
    }

    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
        vk_api_error(422, 'message_required', 'message is required.');
    }

    $sent = 0;
    $failed = 0;
    foreach ($recipients as $phone) {
        $r = sms_send($phone, $message, ['created_by' => $callerId]);
        $r['success'] ? $sent++ : $failed++;
    }

    $_SESSION['user_id'] = $callerId; // logCreate() reads the session
    logCreate('SMS', $message . ' → ' . count($recipients) . ' recipient(s)', 'SMS', $callerId);

    // Always 201: a sms_logs row is written for every recipient regardless of
    // gateway outcome, so the request itself succeeded — delivery success is
    // reported via sent_count/failed_count, not the HTTP status. Matches
    // api/sms_center.php's own convention (always 200, success is a body
    // field); vk_api_ok() always writes {"status":"success"}, so pairing it
    // with a non-2xx code (this endpoint's first draft used 502 on total
    // failure) would contradict itself.
    vk_api_ok([
        'sent_count'   => $sent,
        'failed_count' => $failed,
        'message'      => "Sent to {$sent} recipient(s)" . ($failed ? ", {$failed} failed" : '') . '.',
    ], 201);
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

[$where, $params] = vk_api_sms_filters($_GET);
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM sms_logs s {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("
    SELECT COUNT(*) AS total, SUM(status='sent') AS sent, SUM(status='failed') AS failed, SUM(status='queued') AS queued
    FROM sms_logs
");
$st->execute();
$stats = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0];

$st = $pdo->prepare("
    SELECT s.*, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS sender_name
      FROM sms_logs s
      LEFT JOIN users u ON u.user_id = s.created_by
      {$whereSql}
     ORDER BY s.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok([
    'sms' => array_map('vk_api_sms_row', $rows),
    'stats' => [
        'total'  => (int) $stats['total'],
        'sent'   => (int) $stats['sent'],
        'failed' => (int) $stats['failed'],
        'queued' => (int) $stats['queued'],
    ],
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($rows)) < $total,
    ],
]);
