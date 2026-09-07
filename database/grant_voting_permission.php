<?php
/**
 * database/grant_voting_permission.php
 * -------------------------------------
 * `create_voting_tables.php` explicitly grants `manage_voting` to leadership
 * (Admin/Chairperson/Secretary/Treasurer) so the new key reaches Secretary/
 * Treasurer on existing deployments — the same reason every other "new
 * permission key" migration in this codebase carries its own leadership
 * grant. It never did the same for the plain `voting` key (the page a member
 * uses to cast their OWN vote), which only reaches the `Member` role via
 * seed_vicoba_roles.php's blanket "view-only on every existing key" reseed.
 *
 * Secretary and Treasurer are not `isAdmin()` (core/permissions.php is
 * explicit: "Secretary and Treasurer are NOT full admins"), so on a database
 * seeded only by create_voting_tables.php + seed_vicoba_roles.php, a
 * Secretary or Treasurer — group members like anyone else — cannot open
 * `/voting` to cast their own personal ballot. They can create and manage
 * elections via `manage_voting`, just not vote in one themselves. This grants
 * `voting`, view-only (matching Member's own scope — canCreate/canEdit/
 * canDelete are never checked anywhere for this key), to the same leadership
 * name list `manage_voting` already uses.
 *
 * Rights are RAISED, not overwritten, mirroring
 * create_leadership_applications_table.php's $grantTo() pattern: an admin who
 * has widened a role's access through the Roles screen keeps it.
 *
 * Idempotent — safe to run repeatedly. Registered in database/migrate.php,
 * after create_voting_tables.php.
 *
 * Run manually:  php database/grant_voting_permission.php
 */

require_once __DIR__ . '/../includes/config.php';

$permCheck = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$permCheck->execute(['voting']);
$pid = $permCheck->fetchColumn();

if (!$pid) {
    echo "  'voting' permission not present — skipped (create_voting_tables.php runs first).\n";
    return;
}

$leadership = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
               'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];

$in  = implode(',', array_fill(0, count($leadership), '?'));
$ids = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$ids->execute(array_map('strtolower', $leadership));

$has   = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
$grant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 0, 0, 0)");
$raise = $pdo->prepare("UPDATE role_permissions SET can_view = 1 WHERE role_id = ? AND permission_id = ? AND can_view = 0");

$added = $raised = 0;
foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $rid) {
    $has->execute([$rid, $pid]);
    if ((int) $has->fetchColumn() === 0) {
        $grant->execute([$rid, $pid]);
        $added++;
    } else {
        $raise->execute([$rid, $pid]);
        $raised += $raise->rowCount() > 0 ? 1 : 0;
    }
}

echo "  Granted 'voting' (view) to $added leadership role(s), raised on $raised.\n";
