<?php
/**
 * database/add_budget_permission.php
 * ------------------------------------
 * Register the `budget` permission page-key and grant it to leadership.
 *
 * THE KEY WAS NEVER IN THE TABLE. Found while building Module 10 (Budgets) of
 * the mobile API. `app/constant/accounts/budget.php` and `budget_details.php`
 * have always gated on `autoEnforcePermission('budget')` /
 * `canView('budget')`, and `api/account/review_budget.php` /
 * `approve_budget.php` on `canReview('budget')` / `canApprove('budget')` — but
 * no row for it exists in `permissions`. The practical effect on the live
 * system: only the `isAdmin()` bypass (Admin/Chairperson) can see Budgets at
 * all today; a Secretary or Treasurer is silently refused a screen they are
 * supposed to run, same shape as the `vicoba_reports` and `mkoba_reconciliation`
 * gaps found in Module 8.
 *
 * LEADERSHIP ONLY, NOT MIRRORED FROM AN EXISTING MEMBER-INCLUSIVE KEY. Unlike
 * `expenses`/`petty_cash` (Module 9), there is no live evidence anywhere —
 * web or API — that a Member was ever meant to see budgets; todo.md's own
 * plan says "leadership only". Granted directly, full rights (view/create/
 * edit/delete/review/approve), matching `add_contributions_permission.php`'s
 * and `add_fines_status_and_permission.php`'s leadership-name-list shape.
 *
 * Existing rows are never overwritten.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_budget_permission.php
 */

require_once __DIR__ . '/../includes/config.php';

const VK_BUDGET_KEY = 'budget';

// 1. The permission row itself.
$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$check->execute([VK_BUDGET_KEY]);
$permId = (int) ($check->fetchColumn() ?: 0);

if ($permId === 0) {
    $pdo->prepare(
        'INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        '',
        VK_BUDGET_KEY,
        'Budget',
        'Create and manage the group\'s budgets',
        'Finance',
    ]);
    $permId = (int) $pdo->lastInsertId();
    echo "  Added '" . VK_BUDGET_KEY . "' permission.\n";
} else {
    echo "  '" . VK_BUDGET_KEY . "' permission already present.\n";
}

// 2. Grants: leadership only, full rights (view/create/edit/delete/review/approve).
$leaderNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
                'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];
$in = implode(',', array_fill(0, count($leaderNames), '?'));
$roleIds = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$roleIds->execute(array_map('strtolower', $leaderNames));
$roleIds = $roleIds->fetchAll(PDO::FETCH_COLUMN);

$has   = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?');
$grant = $pdo->prepare(
    'INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
     VALUES (?, ?, 1, 1, 1, 1, 1, 1)'
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

echo "  Granted full rights to $added leadership role(s); left $kept existing grant(s) untouched.\n";
echo "Budget permission sync complete.\n";
