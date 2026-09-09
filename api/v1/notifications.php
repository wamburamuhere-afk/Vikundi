<?php
/**
 * GET /api/v1/notifications — the caller's own notifications, paginated
 *
 * Mirrors app/constant/communication/notification_center.php / api/get_notifications.php.
 * Scoped to `WHERE user_id = ?` on both the web and here — a notification is
 * always personal, so there is no group-wide variant to gate leadership-only.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_communication.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'notification_center');
$callerId = (int) $auth['user_id'];

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

[$where, $params] = vk_api_notification_filters($_GET);
array_unshift($where, 'n.user_id = ?');
array_unshift($params, $callerId);
$whereSql = 'WHERE ' . implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM notifications n {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("
    SELECT n.notification_id, n.title, n.message, n.type, n.priority, n.is_read, n.action_url, n.created_at, n.read_at
      FROM notifications n
      {$whereSql}
     ORDER BY n.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$statsStmt = $pdo->prepare("
    SELECT
      COUNT(*) AS total,
      COALESCE(SUM(is_read = 0), 0) AS unread,
      COALESCE(SUM(is_read = 0 AND priority = 'high'), 0) AS high_unread,
      COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today
    FROM notifications WHERE user_id = ?
");
$statsStmt->execute([$callerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'unread' => 0, 'high_unread' => 0, 'today' => 0];

vk_api_ok([
    'notifications' => array_map('vk_api_notification_row', $rows),
    'stats' => [
        'total'       => (int) $stats['total'],
        'unread'      => (int) $stats['unread'],
        'high_unread' => (int) $stats['high_unread'],
        'today'       => (int) $stats['today'],
    ],
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($rows)) < $total,
    ],
]);
