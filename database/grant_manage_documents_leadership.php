<?php
/**
 * database/grant_manage_documents_leadership.php
 * ----------------------------------------------
 * Mirrors grant_meetings_to_leadership.php for the `manage_documents` page-key
 * (the in-system Document Writer). The permission is registered AFTER the role
 * seeder runs, and the seeder only seeds leadership roles that have no rows yet,
 * so on existing deployments Secretary/Treasurer never received it. Grant it
 * directly.
 *
 * Also removes any `manage_documents` grant from the Member role: the Document
 * Writer is a leadership tool, so ordinary members must not view it. (A member
 * who must SIGN a specific document is given scoped access to that document by
 * the signing flow, not this general permission.)
 *
 * Idempotent — only grants when the row is missing; only deletes the Member row.
 *
 * Run manually:  php database/grant_manage_documents_leadership.php
 */

require_once __DIR__ . '/../includes/config.php';

$permId = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$permId->execute(['manage_documents']);
$permId = $permId->fetchColumn();

if (!$permId) {
    echo "  'manage_documents' permission not present — skipped (create_authored_documents_table runs first).\n";
    return;
}

// 1. Grant to leadership roles (resolved by name; Secretary/Treasurer are not admin-bypassed).
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

// 2. Remove the Document Writer from ordinary Members (belt-and-suspenders for DBs
//    where an earlier run granted it before it entered the member hidden-list).
$memberIds = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ('member', 'mwanachama')");
$memberIds->execute();
$memberIds = $memberIds->fetchAll(PDO::FETCH_COLUMN);

$revoke  = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?");
$revoked = 0;
foreach ($memberIds as $mid) {
    $revoke->execute([$mid, $permId]);
    $revoked += $revoke->rowCount();
}

echo "Document Writer leadership grant complete. Granted to $granted role(s); removed from $revoked member row(s).\n";
