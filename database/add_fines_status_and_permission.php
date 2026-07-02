<?php
/**
 * database/add_fines_status_and_permission.php
 * --------------------------------------------
 * Fines pages:
 *   1. Widen fines.status to allow a committee-forgiven state:
 *      enum('pending','paid')  ->  enum('pending','paid','waived')
 *   2. Register the `manage_fines` permission page-key (leadership manage;
 *      members see only their own via my_fines, which needs no page-key).
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php BEFORE
 * seed_vicoba_roles.php so the permission exists when roles are (re)seeded.
 *
 * Run manually:  php database/add_fines_status_and_permission.php
 */

require_once __DIR__ . '/../includes/config.php';

// 1. Widen the status enum (only if 'waived' is missing).
$tbl = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fines'")->fetchColumn();
if ((int) $tbl > 0) {
    $type = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fines' AND COLUMN_NAME = 'status'")->fetchColumn();
    if ($type && strpos($type, 'waived') === false) {
        $pdo->exec("ALTER TABLE `fines` MODIFY COLUMN `status` enum('pending','paid','waived') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending'");
        echo "  fines.status widened to include 'waived'.\n";
    } else {
        echo "  fines.status already allows 'waived'.\n";
    }
} else {
    echo "  fines table not present — skipped enum change.\n";
}

// 2. Register the manage_fines permission page-key (idempotent — page_key UNIQUE).
$check = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$check->execute(['manage_fines']);
$permId = $check->fetchColumn();
if (!$permId) {
    $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?,?,?,?,?)")
        ->execute(['', 'manage_fines', 'Fines', 'View and manage member fines', 'Finance']);
    $permId = $pdo->lastInsertId();
    echo "  Added 'manage_fines' permission.\n";
} else {
    echo "  'manage_fines' permission already present.\n";
}

// 3. Grant manage_fines to leadership so it works on EXISTING deployments too.
// The role seeder only seeds leadership when they have no rows, so a brand-new
// page-key would otherwise never reach Secretary/Treasurer (who are not
// admin-bypassed). This is safe because the key is new — no admin has revoked it.
$leaderNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
                'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];
$in = implode(',', array_fill(0, count($leaderNames), '?'));
$roleIds = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$roleIds->execute(array_map('strtolower', $leaderNames));
$roleIds = $roleIds->fetchAll(PDO::FETCH_COLUMN);

$has = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
$grant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 1, 1, 1)");
$granted = 0;
foreach ($roleIds as $rid) {
    $has->execute([$rid, $permId]);
    if ((int) $has->fetchColumn() === 0) { $grant->execute([$rid, $permId]); $granted++; }
}
echo "  Granted manage_fines to $granted leadership role(s).\n";

echo "Fines status + permission sync complete.\n";
