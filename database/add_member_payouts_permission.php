<?php
/**
 * database/add_member_payouts_permission.php
 * ---------------------------------------------
 * Register the `member_payouts` permission page-key and grant it to exactly
 * the roles `app/bms/customer/record_payout.php` already checks.
 *
 * THE KEY WAS NEVER IN THE TABLE — found while building Module 11 (Payouts)
 * of the mobile API. `record_payout.php` gates on a hardcoded role-name array
 * (`$viongozi_roles = ['Admin','Chairperson','Mwenyekiti','Secretary','Katibu']`),
 * exactly the old-style pattern todo.md's own judgment call #3 flags: the API
 * layer should normalize this into the same `role_permissions` check every
 * other module uses, not copy the role-name inconsistency forward.
 *
 * NOTE: THIS ROLE SET IS DELIBERATELY NARROWER THAN EVERY OTHER FINANCIAL
 * MODULE — Treasurer is NOT included. Every other money-handling permission
 * built so far (`expenses`, `petty_cash`, `budget`) grants full leadership
 * (Admin/Chairperson/Secretary/Treasurer); `record_payout.php`'s own role
 * list never has, and this migration mirrors that fact rather than
 * "correcting" it to match the other modules' shape — a payout is member
 * assistance leadership decides on, not a treasury operation.
 *
 * Admin and Chairperson are granted here too even though `isAdmin()` already
 * bypasses this check for them — for row completeness and consistency with
 * every other permission migration in this codebase, and so the grant is
 * visible/editable from the Settings > Permissions screen like any other.
 *
 * View and create only — this table has no review/approve/edit workflow at
 * all; `record_payout.php` writes every row directly as `'paid'`.
 *
 * Existing rows are never overwritten.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_member_payouts_permission.php
 */

require_once __DIR__ . '/../includes/config.php';

const VK_PAYOUTS_KEY = 'member_payouts';

// 1. The permission row itself.
$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$check->execute([VK_PAYOUTS_KEY]);
$permId = (int) ($check->fetchColumn() ?: 0);

if ($permId === 0) {
    $pdo->prepare(
        'INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        '',
        VK_PAYOUTS_KEY,
        'Member Payouts',
        'Record assistance paid out to a member',
        'Finance',
    ]);
    $permId = (int) $pdo->lastInsertId();
    echo "  Added '" . VK_PAYOUTS_KEY . "' permission.\n";
} else {
    echo "  '" . VK_PAYOUTS_KEY . "' permission already present.\n";
}

// 2. Grants: Admin, Chairperson, Secretary only — matches
// record_payout.php's own $viongozi_roles exactly, minus Treasurer.
$roleNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
              'secretary', 'sekretari', 'katibu'];
$in = implode(',', array_fill(0, count($roleNames), '?'));
$roleIds = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$roleIds->execute(array_map('strtolower', $roleNames));
$roleIds = $roleIds->fetchAll(PDO::FETCH_COLUMN);

$has   = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?');
$grant = $pdo->prepare(
    'INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete)
     VALUES (?, ?, 1, 1, 0, 0)'
);

$added = 0;
$kept  = 0;
foreach ($roleIds as $rid) {
    $has->execute([$rid, $permId]);
    if ((int) $has->fetchColumn() > 0) {
        $kept++;
        continue;
    }
    $grant->execute([$rid, $permId]);
    $added++;
}

echo "  Granted view+create to $added role(s); left $kept existing grant(s) untouched.\n";
echo "Member payouts permission sync complete.\n";
