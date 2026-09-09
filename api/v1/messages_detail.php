<?php
/**
 * GET /api/v1/messages/{id} — one message, from the caller's own side of it.
 *
 * Ownership re-checked at the loaded row: the caller must be the sender or a
 * recipient, or this 404s rather than 403s — existence of a message the
 * caller has no part in is not revealed. Mirrors message_center.php's own
 * "opening a message marks it read" behaviour when the caller is the
 * recipient (same UPDATE, same condition, same NOW()).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_communication.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'message_center');
$callerId = (int) $auth['user_id'];

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    vk_api_error(422, 'invalid_id', 'A message id is required.');
}

$st = $pdo->prepare("
    SELECT m.*, mr.is_read, mr.read_at, mr.is_archived,
           TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS sender_name
      FROM messages m
      LEFT JOIN message_recipients mr ON mr.message_id = m.message_id AND mr.recipient_id = ?
      LEFT JOIN users s ON s.user_id = m.sender_id
     WHERE m.message_id = ?
       AND (m.sender_id = ? OR EXISTS (SELECT 1 FROM message_recipients x WHERE x.message_id = m.message_id AND x.recipient_id = ?))
");
$st->execute([$callerId, $id, $callerId, $callerId]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    vk_api_error(404, 'not_found', 'No message was found with that id.');
}

if ((int) $row['sender_id'] !== $callerId && empty($row['is_read'])) {
    $pdo->prepare('UPDATE message_recipients SET is_read = 1, read_at = NOW() WHERE message_id = ? AND recipient_id = ?')
        ->execute([$id, $callerId]);
    $row['is_read'] = 1;
    $row['read_at'] = date('Y-m-d H:i:s');
}

// Everyone who has received this message, for a "who's read it" view — sender only.
$recipients = [];
if ((int) $row['sender_id'] === $callerId) {
    $rst = $pdo->prepare("
        SELECT mr.recipient_id, mr.is_read, mr.read_at,
               TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS recipient_name
          FROM message_recipients mr
          JOIN users u ON u.user_id = mr.recipient_id
         WHERE mr.message_id = ? AND mr.deleted = 0
         ORDER BY u.first_name, u.last_name
    ");
    $rst->execute([$id]);
    $recipients = array_map('vk_api_message_recipients_row', $rst->fetchAll(PDO::FETCH_ASSOC));
}

vk_api_ok([
    'message'    => vk_api_message_row($row, $callerId),
    'recipients' => $recipients,
]);
