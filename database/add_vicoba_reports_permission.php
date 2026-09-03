<?php
/**
 * database/add_vicoba_reports_permission.php
 * -------------------------------------------
 * Register the `vicoba_reports` permission page-key and grant it to
 * leadership.
 *
 * THE KEY WAS NEVER IN THE TABLE. app/bms/customer/financial_ledger.php,
 * app/constant/reports/vicoba_reports.php and
 * app/constant/reports/death_analysis.php have all gated on
 * canView('vicoba_reports') since they were written — but no row for it
 * exists in `permissions`, found while building Module 8 (Financial Ledger &
 * Reconciliation) of the mobile API. The effect is identical to the
 * `manage_contributions` gap add_contributions_permission.php fixed: every
 * check resolves to false for anyone not caught by the isAdmin() bypass
 * (Admin/Chairperson only — see core/permissions.php), so a Secretary or
 * Treasurer opening these reports on the web is silently refused, and the
 * API (which does not do the role-name bypass by design — SEC-015) would
 * refuse them too even though todo.md lists this as leadership-wide.
 *
 * NOT MIRRORED FROM AN EXISTING KEY, unlike add_contributions_permission.php.
 * The Reports-module keys already in the table (financial_statements,
 * income_statement, balance_sheet, trial_balance, loan_portfolio_report,
 * repayment_report) belong to the dead accounting-ledger module (see
 * todo.md's "Excluded" section) and carry Member view=1 grants left over
 * from the BMS seed data — copying that set would wrongly hand a plain
 * Member access to leadership reports. Granted directly, view-only: no
 * screen that gates on this key has a create/edit/delete action.
 *
 * Existing rows are never overwritten: if an admin has already tuned this
 * key, re-running the migration leaves their choice alone.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_vicoba_reports_permission.php
 */

require_once __DIR__ . '/../includes/config.php';

const VK_VICOBA_REPORTS_KEY = 'vicoba_reports';

// 1. The permission row itself.
$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$check->execute([VK_VICOBA_REPORTS_KEY]);
$permId = (int) ($check->fetchColumn() ?: 0);

if ($permId === 0) {
    $pdo->prepare(
        'INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        '',
        VK_VICOBA_REPORTS_KEY,
        'VICOBA Reports',
        'View the group financial ledger, VICOBA summary and death-analysis reports',
        'Reports',
    ]);
    $permId = (int) $pdo->lastInsertId();
    echo "  Added '" . VK_VICOBA_REPORTS_KEY . "' permission.\n";
} else {
    echo "  '" . VK_VICOBA_REPORTS_KEY . "' permission already present.\n";
}

// 2. Grants: leadership only, view-only (no create/edit/delete action exists
// on any page gated by this key).
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

echo "  Granted view to $added leadership role(s); left $kept existing grant(s) untouched.\n";
echo "vicoba_reports permission sync complete.\n";
