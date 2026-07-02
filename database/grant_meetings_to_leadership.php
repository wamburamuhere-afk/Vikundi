<?php
/**
 * database/grant_meetings_to_leadership.php
 * -----------------------------------------
 * Fixes the same gap the fines migration handled, for the `meetings` page-key
 * (added in the Meetings core PR). Secretary and Treasurer are NOT admin-bypassed
 * and the role seeder only seeds leadership when they have no rows, so on existing
 * deployments the `meetings` key never reached them. Grant it directly.
 *
 * Idempotent — only grants when the row is missing; the key being new means no
 * admin has deliberately revoked it.
 *
 * Run manually:  php database/grant_meetings_to_leadership.php
 */

require_once __DIR__ . '/../includes/config.php';

$permId = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$permId->execute(['meetings']);
$permId = $permId->fetchColumn();

if (!$permId) {
    echo "  'meetings' permission not present — skipped (create_meetings_tables runs first).\n";
    return;
}

$leaderNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
                'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];
$in = implode(',', array_fill(0, count($leaderNames), '?'));
$roleIds = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$roleIds->execute(array_map('strtolower', $leaderNames));
$roleIds = $roleIds->fetchAll(PDO::FETCH_COLUMN);

$has   = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
$grant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 1, 1, 1)");

$granted = 0;
foreach ($roleIds as $rid) {
    $has->execute([$rid, $permId]);
    if ((int) $has->fetchColumn() === 0) { $grant->execute([$rid, $permId]); $granted++; }
}

echo "Meetings leadership grant complete. Granted to $granted role(s).\n";
