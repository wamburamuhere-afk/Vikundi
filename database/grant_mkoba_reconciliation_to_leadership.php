<?php
/**
 * database/grant_mkoba_reconciliation_to_leadership.php
 * -------------------------------------------------------
 * Fixes the same gap grant_meetings_to_leadership.php handled, for the
 * `mkoba_reconciliation` page-key.
 *
 * create_mkoba_statement_rows_table.php registers the permission ROW but was
 * never followed by a grant migration, so on every existing deployment the
 * key has a catalog entry and zero role_permissions rows — found while
 * building Module 8 (Financial Ledger & Reconciliation) of the mobile API.
 * Secretary and Treasurer are not admin-bypassed (core/permissions.php's
 * isAdmin() covers only Admin/Chairperson), so both are silently refused the
 * group-wide M-Koba reconciliation on the web today, and the API — which
 * does not do the role-name bypass by design (SEC-015) — would refuse them
 * identically.
 *
 * View-only, unlike grant_meetings_to_leadership.php's full CRUD grant: this
 * page has no create/edit/delete action anywhere in the codebase.
 *
 * Idempotent — only grants when the row is missing; the key already having a
 * catalog entry with no grants means no admin has deliberately configured it.
 *
 * Run manually:  php database/grant_mkoba_reconciliation_to_leadership.php
 */

require_once __DIR__ . '/../includes/config.php';

$permId = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$permId->execute(['mkoba_reconciliation']);
$permId = $permId->fetchColumn();

if (!$permId) {
    echo "  'mkoba_reconciliation' permission not present — skipped (create_mkoba_statement_rows_table runs first).\n";
    return;
}

$leaderNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
                'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];
$in = implode(',', array_fill(0, count($leaderNames), '?'));
$roleIds = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$roleIds->execute(array_map('strtolower', $leaderNames));
$roleIds = $roleIds->fetchAll(PDO::FETCH_COLUMN);

$has   = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?');
$grant = $pdo->prepare(
    'INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete)
     VALUES (?, ?, 1, 0, 0, 0)'
);

$granted = 0;
foreach ($roleIds as $rid) {
    $has->execute([$rid, $permId]);
    if ((int) $has->fetchColumn() === 0) {
        $grant->execute([$rid, $permId]);
        $granted++;
    }
}

echo "M-Koba reconciliation leadership grant complete. Granted to $granted role(s).\n";
