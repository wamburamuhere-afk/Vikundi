<?php
/**
 * GET  /api/v1/messages — the caller's own inbox/sent/archived, paginated
 * POST /api/v1/messages — send a message to one or more recipients
 *
 * Mirrors app/constant/communication/message_center.php.
 *
 * SCOPE, corrected from todo.md's "leadership only" plan text: `message_center`
 * is view-granted to every Member by default (it is not in
 * vk_member_hidden_keys()), and the web's own queries are already scoped by
 * sender_id/recipient_id — this is an inbox, available to every authenticated
 * user, not a group-wide list. See includes/api_communication.php's header.
 *
 * SENDING now requires `create` on `message_center` — a real gap fixed in the
 * same change as the web's own message_center.php (see that file's own note).
 * Previously any signed-in Member could POST send_message directly and
 * message anyone; only the page-level view-check gated the page at all.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_communication.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'message_center');
$callerId = (int) $auth['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vk_api_comm_require_leader(
        $auth,
        'Sending messages is available to leadership only. You can still read messages sent to you.'
    );

    $body = vk_api_body();

    $recipientIds = vk_api_messages_validate_recipients($pdo, $body['recipient_ids'] ?? null, $callerId);

    $subject = trim((string) ($body['subject'] ?? ''));
    if ($subject === '') {
        vk_api_error(422, 'subject_required', 'subject is required.');
    }

    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
        vk_api_error(422, 'message_required', 'message is required.');
    }

    $priority = trim((string) ($body['priority'] ?? 'normal'));
    if (!in_array($priority, vk_api_message_priorities(), true)) {
        vk_api_error(422, 'invalid_priority', 'priority must be one of: ' . implode(', ', vk_api_message_priorities()) . '.');
    }

    $parentId = null;
    if (!empty($body['parent_id'])) {
        $parentId = (int) $body['parent_id'];
        // A reply must be to a thread the caller can actually see (sent it, or received it).
        $chk = $pdo->prepare("
            SELECT 1 FROM messages m
             WHERE m.message_id = ?
               AND (m.sender_id = ?
                    OR EXISTS (SELECT 1 FROM message_recipients mr WHERE mr.message_id = m.message_id AND mr.recipient_id = ?))
        ");
        $chk->execute([$parentId, $callerId, $callerId]);
        if (!$chk->fetchColumn()) {
            vk_api_error(404, 'parent_not_found', 'No message was found with that parent_id.');
        }
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'INSERT INTO messages (sender_id, subject, message, priority, parent_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $st->execute([$callerId, $subject, $message, $priority, $parentId]);
        $messageId = (int) $pdo->lastInsertId();

        $rst = $pdo->prepare('INSERT INTO message_recipients (message_id, recipient_id, created_at) VALUES (?, ?, NOW())');
        foreach ($recipientIds as $rid) {
            $rst->execute([$messageId, $rid]);
        }

        if ($parentId) {
            $pdo->prepare('UPDATE messages SET has_replies = 1 WHERE message_id = ?')->execute([$parentId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        vk_api_error(500, 'send_failed', 'The message could not be sent.');
    }

    $_SESSION['user_id'] = $callerId; // logCreate() reads the session
    logCreate('Messages', $subject . ' → ' . count($recipientIds) . ' recipient(s)', 'MESSAGE#' . $messageId, $callerId);

    $row = $pdo->prepare('SELECT * FROM messages WHERE message_id = ?');
    $row->execute([$messageId]);

    vk_api_ok([
        'message' => vk_api_message_row($row->fetch(PDO::FETCH_ASSOC), $callerId),
        'sent_to' => count($recipientIds),
    ], 201);
}

$folder = trim((string) ($_GET['folder'] ?? 'inbox'));
if (!in_array($folder, vk_api_message_folders(), true)) {
    vk_api_error(422, 'invalid_folder', 'folder must be one of: ' . implode(', ', vk_api_message_folders()) . '.');
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

if ($folder === 'sent') {
    $from  = 'FROM messages m LEFT JOIN users s ON s.user_id = m.sender_id';
    $where = 'WHERE m.sender_id = ? AND m.deleted_by_sender = 0 AND m.is_archived_by_sender = 0';
    $params = [$callerId];
} elseif ($folder === 'archived') {
    $from  = 'FROM messages m
              LEFT JOIN message_recipients mr ON mr.message_id = m.message_id AND mr.recipient_id = ?
              LEFT JOIN users s ON s.user_id = m.sender_id';
    $where = 'WHERE (mr.recipient_id = ? AND mr.is_archived = 1 AND mr.deleted = 0)
                 OR (m.sender_id = ? AND m.is_archived_by_sender = 1 AND m.deleted_by_sender = 0)';
    $params = [$callerId, $callerId, $callerId];
} else { // inbox
    $from  = 'FROM message_recipients mr
              JOIN messages m ON m.message_id = mr.message_id
              LEFT JOIN users s ON s.user_id = m.sender_id';
    $where = 'WHERE mr.recipient_id = ? AND mr.deleted = 0 AND mr.is_archived = 0';
    $params = [$callerId];
}

$select = $folder === 'sent'
    ? "m.*, TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS sender_name"
    : "m.*, mr.is_read, mr.read_at, mr.is_archived, TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS sender_name";

$st = $pdo->prepare("SELECT COUNT(*) {$from} {$where}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT {$select} {$from} {$where} ORDER BY m.created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$statsStmt = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM message_recipients mr WHERE mr.recipient_id = ? AND mr.deleted = 0 AND mr.is_archived = 0 AND mr.is_read = 0) AS unread_count,
      (SELECT COUNT(*) FROM message_recipients mr WHERE mr.recipient_id = ? AND mr.deleted = 0 AND mr.is_archived = 0) AS inbox_count,
      (SELECT COUNT(*) FROM messages WHERE sender_id = ? AND deleted_by_sender = 0 AND is_archived_by_sender = 0) AS sent_count
");
$statsStmt->execute([$callerId, $callerId, $callerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: ['unread_count' => 0, 'inbox_count' => 0, 'sent_count' => 0];

vk_api_ok([
    'folder'   => $folder,
    'messages' => array_map(fn($r) => vk_api_message_row($r, $callerId), $rows),
    'stats' => [
        'unread_count' => (int) $stats['unread_count'],
        'inbox_count'  => (int) $stats['inbox_count'],
        'sent_count'   => (int) $stats['sent_count'],
    ],
    'can_send' => vk_api_comm_is_leader($auth),
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($rows)) < $total,
    ],
]);
