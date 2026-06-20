<?php
/**
 * Email Center API — comms > Email
 * --------------------------------------------------------------
 * JSON endpoint backing app/constant/communication/email_center.php.
 * Mirrors the conventions used by the rest of /api: session check,
 * RBAC permission gate, PDO prepared statements, audit logging and
 * a uniform { success, message/data } envelope.
 *
 * Actions (via ?action= or POST action):
 *   list        GET   List email logs (+ stats)
 *   get         GET   Fetch a single email log by id
 *   recipients  GET   Member/user email address book for the picker
 *   send        POST  Compose & send to one or more recipients
 *   resend      POST  Resend an existing logged email
 *   delete      POST  Delete an email log row
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/email_helper.php';

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

function email_api_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    email_api_fail($is_sw ? 'Hujaingia kwenye mfumo.' : 'Not authenticated.', 401);
}

// View permission is the baseline for every action; create/delete are
// enforced per-action below. Email Center shares the message_center key.
$page_key = 'message_center';
if (!canView($page_key)) {
    email_api_fail($is_sw ? 'Huna ruhusa.' : 'Permission denied.', 403);
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    email_ensure_logs_table($pdo);

    switch ($action) {

        // -----------------------------------------------------------------
        case 'list':
            $stats = $pdo->query("
                SELECT
                    COUNT(*) AS total,
                    SUM(status = 'sent')   AS sent,
                    SUM(status = 'failed') AS failed,
                    SUM(status = 'queued') AS queued
                FROM email_logs
            ")->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0];

            $rows = $pdo->query("
                SELECT e.email_id, e.recipient_email, e.recipient_name, e.subject,
                       e.status, e.error_message, e.sent_at, e.created_at,
                       TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS sender_name
                FROM email_logs e
                LEFT JOIN users u ON e.created_by = u.user_id
                ORDER BY e.created_at DESC
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

        // -----------------------------------------------------------------
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                email_api_fail($is_sw ? 'Kitambulisho si sahihi.' : 'Invalid id.');
            }
            $stmt = $pdo->prepare("
                SELECT e.*, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS sender_name
                FROM email_logs e
                LEFT JOIN users u ON e.created_by = u.user_id
                WHERE e.email_id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                email_api_fail($is_sw ? 'Barua pepe haipatikani.' : 'Email not found.', 404);
            }
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // -----------------------------------------------------------------
        case 'search_recipients':
            // AJAX address book for the Select2 recipient picker (§UI-3:
            // large dataset → search by typing, filtered server-side).
            // Returns Select2 grouped results: { results: [{text, children:[{id,text}]}] }.
            $q    = trim($_GET['q'] ?? '');
            $like = '%' . $q . '%';
            // Show a short preview before the user types (5 per group), then a
            // fuller filtered list once they start typing.
            $limit = $q === '' ? 5 : 25;

            $members_stmt = $pdo->prepare("
                SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, email
                FROM customers
                WHERE email IS NOT NULL AND email <> ''
                  AND (? = '' OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?
                       OR CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE ?)
                ORDER BY first_name, last_name
                LIMIT $limit
            ");
            $members_stmt->execute([$q, $like, $like, $like, $like]);
            $members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);

            $staff_stmt = $pdo->prepare("
                SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, email
                FROM users
                WHERE email IS NOT NULL AND email <> '' AND is_active = 1
                  AND (? = '' OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?
                       OR CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE ?)
                ORDER BY first_name, last_name
                LIMIT $limit
            ");
            $staff_stmt->execute([$q, $like, $like, $like, $like]);
            $staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

            $toChildren = function (array $rows) {
                $out = [];
                foreach ($rows as $r) {
                    if (empty($r['email'])) continue;
                    $name = trim($r['name'] ?? '');
                    $out[] = ['id' => $r['email'], 'text' => $name !== '' ? "$name <{$r['email']}>" : $r['email']];
                }
                return $out;
            };

            $results = [];
            $member_children = $toChildren($members);
            $staff_children  = $toChildren($staff);
            if ($member_children) $results[] = ['text' => ($is_sw ? 'Wanachama' : 'Members'), 'children' => $member_children];
            if ($staff_children)  $results[] = ['text' => ($is_sw ? 'Wafanyakazi' : 'Staff'),   'children' => $staff_children];

            echo json_encode(['results' => $results]);
            break;

        // -----------------------------------------------------------------
        case 'send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                email_api_fail($is_sw ? 'Njia si sahihi.' : 'POST required.', 405);
            }
            if (!canCreate($page_key)) {
                email_api_fail($is_sw ? 'Huna ruhusa ya kutuma.' : 'You do not have permission to send email.', 403);
            }

            $recipients = email_parse_recipients($_POST['recipients'] ?? '');
            $subject    = trim($_POST['subject'] ?? '');
            $body       = trim($_POST['body'] ?? '');

            if (empty($recipients)) {
                email_api_fail($is_sw ? 'Weka angalau anwani moja sahihi ya barua pepe.' : 'Enter at least one valid recipient email address.');
            }
            if ($subject === '') {
                email_api_fail($is_sw ? 'Mada inahitajika.' : 'Subject is required.');
            }
            if ($body === '') {
                email_api_fail($is_sw ? 'Maudhui ya barua pepe yanahitajika.' : 'Email body is required.');
            }

            $sent_count = 0;
            $fail_count = 0;
            foreach ($recipients as $addr) {
                $res = email_send($addr, $subject, $body, ['created_by' => $user_id]);
                if ($res['success']) {
                    $sent_count++;
                } else {
                    $fail_count++;
                }
            }

            logCreate('Email', $subject . ' → ' . count($recipients) . ' recipient(s)', 'EMAIL', $user_id);

            $msg = $is_sw
                ? "Imetumwa kwa wapokeaji $sent_count" . ($fail_count ? ", imeshindwa $fail_count" : '') . '.'
                : "Sent to $sent_count recipient(s)" . ($fail_count ? ", $fail_count failed" : '') . '.';

            echo json_encode([
                'success'    => $sent_count > 0,
                'message'    => $msg,
                'sent_count' => $sent_count,
                'fail_count' => $fail_count,
            ]);
            break;

        // -----------------------------------------------------------------
        case 'resend':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                email_api_fail($is_sw ? 'Njia si sahihi.' : 'POST required.', 405);
            }
            if (!canCreate($page_key)) {
                email_api_fail($is_sw ? 'Huna ruhusa ya kutuma.' : 'You do not have permission to send email.', 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                email_api_fail($is_sw ? 'Kitambulisho si sahihi.' : 'Invalid id.');
            }
            $stmt = $pdo->prepare("SELECT * FROM email_logs WHERE email_id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                email_api_fail($is_sw ? 'Barua pepe haipatikani.' : 'Email not found.', 404);
            }

            $res = email_send($row['recipient_email'], $row['subject'], $row['body'], [
                'recipient_name' => $row['recipient_name'],
                'created_by'     => $user_id,
            ]);

            logCreate('Email', 'Resend: ' . $row['subject'] . ' → ' . $row['recipient_email'], 'EMAIL#' . $id, $user_id);

            echo json_encode([
                'success' => $res['success'],
                'message' => $res['success']
                    ? ($is_sw ? 'Barua pepe imetumwa tena.' : 'Email resent.')
                    : $res['message'],
            ]);
            break;

        // -----------------------------------------------------------------
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                email_api_fail($is_sw ? 'Njia si sahihi.' : 'POST required.', 405);
            }
            if (!canDelete($page_key)) {
                email_api_fail($is_sw ? 'Huna ruhusa ya kufuta.' : 'You do not have permission to delete.', 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                email_api_fail($is_sw ? 'Kitambulisho si sahihi.' : 'Invalid id.');
            }
            $stmt = $pdo->prepare("SELECT subject, recipient_email FROM email_logs WHERE email_id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                email_api_fail($is_sw ? 'Barua pepe haipatikani.' : 'Email not found.', 404);
            }

            $pdo->prepare("DELETE FROM email_logs WHERE email_id = ?")->execute([$id]);
            logDelete('Email', $row['subject'] . ' → ' . $row['recipient_email'], 'EMAIL#' . $id, $user_id);

            echo json_encode(['success' => true, 'message' => $is_sw ? 'Barua pepe imefutwa.' : 'Email deleted.']);
            break;

        // -----------------------------------------------------------------
        default:
            email_api_fail($is_sw ? 'Kitendo hakijulikani.' : 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('email_center API: ' . $e->getMessage());
    email_api_fail(($is_sw ? 'Hitilafu ya seva: ' : 'Server error: ') . $e->getMessage(), 500);
}
