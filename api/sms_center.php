<?php
/**
 * SMS Center API — comms > SMS
 * JSON endpoint backing app/constant/communication/sms_center.php.
 * Mirrors api/email_center.php: session + RBAC gate, prepared statements,
 * audit logging, uniform { success, ... } envelope.
 *
 * Actions: list, get, search_recipients, send, resend, delete
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/sms_helper.php';

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

function sms_api_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    sms_api_fail($is_sw ? 'Hujaingia kwenye mfumo.' : 'Not authenticated.', 401);
}

// Shares the message_center permission key, like the Email Center.
$page_key = 'message_center';
if (!canView($page_key)) {
    sms_api_fail($is_sw ? 'Huna ruhusa.' : 'Permission denied.', 403);
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    sms_ensure_logs_table($pdo);

    switch ($action) {

        case 'list':
            $stats = $pdo->query("
                SELECT COUNT(*) AS total,
                       SUM(status='sent')   AS sent,
                       SUM(status='failed') AS failed,
                       SUM(status='queued') AS queued
                FROM sms_logs
            ")->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0];

            $rows = $pdo->query("
                SELECT s.sms_id, s.recipient_phone, s.recipient_name, s.message, s.status,
                       s.provider, s.error_message, s.segments, s.sent_at, s.created_at,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS sender_name
                FROM sms_logs s
                LEFT JOIN users u ON s.created_by = u.user_id
                ORDER BY s.created_at DESC
                LIMIT 500
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data'    => $rows,
                'stats'   => [
                    'total'  => (int)$stats['total'],
                    'sent'   => (int)$stats['sent'],
                    'failed' => (int)$stats['failed'],
                    'queued' => (int)$stats['queued'],
                ],
            ]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) sms_api_fail($is_sw ? 'Kitambulisho si sahihi.' : 'Invalid id.');
            $stmt = $pdo->prepare("
                SELECT s.*, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS sender_name
                FROM sms_logs s LEFT JOIN users u ON s.created_by = u.user_id
                WHERE s.sms_id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) sms_api_fail($is_sw ? 'SMS haipatikani.' : 'SMS not found.', 404);
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        case 'search_recipients':
            // AJAX address book: members with a phone number (§UI-3).
            $q    = trim($_GET['q'] ?? '');
            $like = '%' . $q . '%';
            $limit = $q === '' ? 5 : 25;
            $stmt = $pdo->prepare("
                SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name,
                       COALESCE(NULLIF(phone,''), mobile) AS phone
                FROM customers
                WHERE COALESCE(NULLIF(phone,''), mobile) IS NOT NULL
                  AND COALESCE(NULLIF(phone,''), mobile) <> ''
                  AND (? = '' OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR mobile LIKE ?
                       OR CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE ?)
                ORDER BY first_name, last_name
                LIMIT $limit
            ");
            $stmt->execute([$q, $like, $like, $like, $like, $like]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $children = [];
            foreach ($members as $m) {
                if (empty($m['phone'])) continue;
                $name = trim($m['name'] ?? '');
                $children[] = ['id' => $m['phone'], 'text' => ($name !== '' ? "$name — {$m['phone']}" : $m['phone'])];
            }
            $results = $children ? [['text' => ($is_sw ? 'Wanachama' : 'Members'), 'children' => $children]] : [];
            echo json_encode(['results' => $results]);
            break;

        case 'send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') sms_api_fail($is_sw ? 'Njia si sahihi.' : 'POST required.', 405);
            if (!canCreate($page_key)) sms_api_fail($is_sw ? 'Huna ruhusa ya kutuma.' : 'You do not have permission to send SMS.', 403);

            $raw     = $_POST['recipients'] ?? '';
            $message = trim($_POST['message'] ?? '');
            // Split on comma/semicolon/space/newline; normalise + dedupe.
            $parts = preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $recipients = [];
            foreach ($parts as $p) {
                $n = sms_normalize_phone($p);
                if ($n !== '' && strlen($n) >= 10 && !in_array($n, $recipients, true)) $recipients[] = $n;
            }

            if (empty($recipients)) sms_api_fail($is_sw ? 'Weka angalau namba moja sahihi ya simu.' : 'Enter at least one valid phone number.');
            if ($message === '')   sms_api_fail($is_sw ? 'Andika ujumbe.' : 'Message is required.');

            $sent = 0; $fail = 0;
            foreach ($recipients as $num) {
                $r = sms_send($num, $message, ['created_by' => $user_id]);
                $r['success'] ? $sent++ : $fail++;
            }

            logCreate('SMS', $message . ' → ' . count($recipients) . ' recipient(s)', 'SMS', $user_id);

            $msg = $is_sw
                ? "Imetumwa kwa $sent" . ($fail ? ", imeshindwa $fail" : '') . '.'
                : "Sent to $sent" . ($fail ? ", $fail failed" : '') . '.';
            echo json_encode(['success' => $sent > 0, 'message' => $msg, 'sent_count' => $sent, 'fail_count' => $fail]);
            break;

        case 'resend':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') sms_api_fail($is_sw ? 'Njia si sahihi.' : 'POST required.', 405);
            if (!canCreate($page_key)) sms_api_fail($is_sw ? 'Huna ruhusa ya kutuma.' : 'You do not have permission to send SMS.', 403);
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) sms_api_fail($is_sw ? 'Kitambulisho si sahihi.' : 'Invalid id.');
            $stmt = $pdo->prepare("SELECT * FROM sms_logs WHERE sms_id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) sms_api_fail($is_sw ? 'SMS haipatikani.' : 'SMS not found.', 404);

            $r = sms_send($row['recipient_phone'], $row['message'], ['recipient_name' => $row['recipient_name'], 'created_by' => $user_id]);
            logCreate('SMS', 'Resend → ' . $row['recipient_phone'], 'SMS#' . $id, $user_id);
            echo json_encode(['success' => $r['success'], 'message' => $r['success'] ? ($is_sw ? 'SMS imetumwa tena.' : 'SMS resent.') : $r['message']]);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') sms_api_fail($is_sw ? 'Njia si sahihi.' : 'POST required.', 405);
            if (!canDelete($page_key)) sms_api_fail($is_sw ? 'Huna ruhusa ya kufuta.' : 'You do not have permission to delete.', 403);
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) sms_api_fail($is_sw ? 'Kitambulisho si sahihi.' : 'Invalid id.');
            $stmt = $pdo->prepare("SELECT recipient_phone FROM sms_logs WHERE sms_id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) sms_api_fail($is_sw ? 'SMS haipatikani.' : 'SMS not found.', 404);
            $pdo->prepare("DELETE FROM sms_logs WHERE sms_id = ?")->execute([$id]);
            logDelete('SMS', 'SMS → ' . $row['recipient_phone'], 'SMS#' . $id, $user_id);
            echo json_encode(['success' => true, 'message' => $is_sw ? 'SMS imefutwa.' : 'SMS deleted.']);
            break;

        default:
            sms_api_fail($is_sw ? 'Kitendo hakijulikani.' : 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('sms_center API: ' . $e->getMessage());
    sms_api_fail(($is_sw ? 'Hitilafu ya seva: ' : 'Server error: ') . $e->getMessage(), 500);
}
