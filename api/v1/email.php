<?php
/**
 * POST /api/v1/email — send an email to one or more addresses
 *
 * ADDED — not in todo.md's original Module 16 plan, which only asked for
 * `/email-templates`. email_center.php is real, functional (SMTP via
 * includes/email_helper.php) and nav-reachable, and POST /api/v1/sms already
 * gives the mobile app SMS parity; email would otherwise have none at all.
 *
 * Send-only, deliberately. api/email_center.php's own `?action=list` has the
 * same whole-group-log exposure as sms_center's (see
 * includes/api_communication.php's header on GET /api/v1/sms) — there was no
 * reason to build a second copy of that hole when nothing in todo.md asked
 * for an email log endpoint in the first place.
 *
 * Gated on `create` on `message_center`, same as SMS — Email Center shares
 * that key on the web too (api/email_center.php's own comment says so).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_communication.php';
require_once __DIR__ . '/../../includes/activity_logger.php';
require_once __DIR__ . '/../../includes/email_helper.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
$callerId = (int) $auth['user_id'];

vk_api_comm_require_leader($auth, 'Email is available to leadership only.');

$body = vk_api_body();

$raw = $body['recipients'] ?? '';
$addresses = is_array($raw) ? $raw : (email_parse_recipients((string) $raw));
$addresses = array_values(array_unique(array_filter(array_map('trim', $addresses), 'email_is_valid')));
if (!$addresses) {
    vk_api_error(422, 'recipients_required', 'Enter at least one valid email address in recipients.');
}

$subject = trim((string) ($body['subject'] ?? ''));
if ($subject === '') {
    vk_api_error(422, 'subject_required', 'subject is required.');
}

$emailBody = trim((string) ($body['body'] ?? ''));
if ($emailBody === '') {
    vk_api_error(422, 'body_required', 'body is required.');
}

$sent = 0;
$failed = 0;
foreach ($addresses as $addr) {
    $r = email_send($addr, $subject, $emailBody, ['created_by' => $callerId]);
    $r['success'] ? $sent++ : $failed++;
}

$_SESSION['user_id'] = $callerId; // logCreate() reads the session
logCreate('Email', $subject . ' → ' . count($addresses) . ' recipient(s)', 'EMAIL', $callerId);

// Always 201 — see api/v1/sms.php's identical note on why delivery outcome
// belongs in the body (sent_count/failed_count), not the HTTP status.
vk_api_ok([
    'sent_count'   => $sent,
    'failed_count' => $failed,
    'message'      => "Sent to {$sent} recipient(s)" . ($failed ? ", {$failed} failed" : '') . '.',
], 201);
