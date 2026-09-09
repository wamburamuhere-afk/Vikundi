<?php
/**
 * POST /api/v1/notifications/{id}/read — mark one of the caller's own
 * notifications as read.
 *
 * Mirrors api/mark_notification_read.php's ownership rule (`user_id = ?` in
 * the UPDATE), but 404s when the row isn't the caller's rather than silently
 * no-op'ing a well-formed request — the caller gets to know the id was bad.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'notification_center');
$callerId = (int) $auth['user_id'];

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    vk_api_error(422, 'invalid_id', 'A notification id is required.');
}

$chk = $pdo->prepare('SELECT 1 FROM notifications WHERE notification_id = ? AND user_id = ?');
$chk->execute([$id, $callerId]);
if (!$chk->fetchColumn()) {
    vk_api_error(404, 'not_found', 'No notification was found with that id.');
}

$pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?')
    ->execute([$id, $callerId]);

vk_api_ok(['notification_id' => $id, 'is_read' => true]);
