<?php
/**
 * GET  /api/v1/meetings — the group's meetings, paginated
 * POST /api/v1/meetings — record one (delegates to meetings_create.php)
 *
 * Mirrors app/constant/meetings/meetings.php and api/get_meetings.php.
 *
 * Gated on `meetings` — an existing key with correct grants (full leadership
 * CRUD, Member view-only), unlike every module since Contributions this key
 * needed no new permission migration.
 *
 * FIXES A GAP: api/get_meetings.php (the web list's own DataTable source) had
 * no permission check at all beyond being logged in — any authenticated
 * Member could pull the list without holding `meetings` view. That is
 * currently a mild gap (Member already holds `view` on this key anyway), but
 * this endpoint is gated properly from the start rather than mirroring it.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_meetings.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/meetings_create.php';
    exit;
}

vk_api_require_permission($auth, 'view', 'meetings');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_meetings_filters($_GET, 'm');

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM meetings m {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT m.*,
           (SELECT COUNT(*) FROM meeting_attendance a WHERE a.meeting_id = m.id AND a.status = 'present') AS present_count
      FROM meetings m
      {$whereSql}
     ORDER BY m.meeting_date DESC, m.id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok([
    'meetings' => array_map('vk_api_meetings_row', $rows),
    'totals' => [
        'filtered_count' => $total,
        'held'           => (int) $pdo->query("SELECT COUNT(*) FROM meetings WHERE status = 'held'")->fetchColumn(),
        'scheduled'      => (int) $pdo->query("SELECT COUNT(*) FROM meetings WHERE status = 'scheduled'")->fetchColumn(),
    ],
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($rows)) < $total,
    ],
]);
