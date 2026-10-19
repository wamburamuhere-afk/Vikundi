<?php
/**
 * database/add_death_expenses_permission.php
 * ------------------------------------------
 * Register the `death_expenses` permission page-key where it is missing, and
 * grant it to the same roles, with the same rights, as `expenses`.
 *
 * WHY IT CAN BE MISSING. The whole bereavement (condolence) module gates on
 * this key — the Expenses list page, the record view, review, approve, mark
 * paid, the mobile API — but no migration ever created the row. Deployments
 * restored from the original database copy carry it; a database built by
 * database/migrate.php alone does not. There, only an admin-role user (the
 * Chairperson) can open Finance > Expenses: the Secretary and the Treasurer
 * follow the menu link and are bounced to "unauthorized". Seen in a browser on
 * the first new-group deployment.
 *
 * THE GRANTS ARE MIRRORED FROM `expenses`, NOT HARDCODED — the same reasoning
 * as add_petty_cash_permission.php: a money record with a pending / reviewed /
 * approved / paid workflow, and `expenses` already carries the deployment's own
 * view of who may do what to that shape of record. A Member's mirrored grant is
 * view-only, which is what includes/death_expense_access.php is written around:
 * `view` shows a member their own record, and only `edit` unlocks the group.
 *
 * A NO-OP WHERE THE KEY EXISTS. An existing row, and every existing grant on
 * it, is left exactly as it is.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php BEFORE
 * seed_vicoba_roles.php.
 *
 * Run manually:  php database/add_death_expenses_permission.php
 */

require_once __DIR__ . '/../includes/config.php';

const VK_DEATH_KEY   = 'death_expenses';
const VK_DEATH_MODEL = 'expenses';

$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$check->execute([VK_DEATH_KEY]);
$permId = (int) ($check->fetchColumn() ?: 0);

if ($permId > 0) {
    echo "  '" . VK_DEATH_KEY . "' permission already present — left as it is.\n";
    return;
}

$pdo->prepare(
    'INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?, ?, ?, ?, ?)'
)->execute(['', VK_DEATH_KEY, 'Bereavement Expenses', 'View, review, approve and pay bereavement (condolence) expenses', 'Accounts']);
$permId = (int) $pdo->lastInsertId();
echo "  Added '" . VK_DEATH_KEY . "' permission.\n";

// The grants, mirrored from the model key.
$check->execute([VK_DEATH_MODEL]);
$modelId = (int) ($check->fetchColumn() ?: 0);

$rows = [];
if ($modelId > 0) {
    $st = $pdo->prepare(
        'SELECT role_id, can_view, can_create, can_edit, can_delete, can_review, can_approve
           FROM role_permissions WHERE permission_id = ?'
    );
    $st->execute([$modelId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo '  Mirroring ' . count($rows) . " grant(s) from '" . VK_DEATH_MODEL . "'.\n";
}

if (!$rows) {
    $leaderNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
                    'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];
    $in = implode(',', array_fill(0, count($leaderNames), '?'));
    $ids = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
    $ids->execute($leaderNames);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $rows[] = ['role_id' => $rid, 'can_view' => 1, 'can_create' => 1, 'can_edit' => 1,
                   'can_delete' => 1, 'can_review' => 1, 'can_approve' => 1];
    }
    echo '  No ' . VK_DEATH_MODEL . ' grants to mirror — granting to ' . count($rows) . " leadership role(s).\n";
}

$grant = $pdo->prepare(
    'INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($rows as $r) {
    $grant->execute([$r['role_id'], $permId, (int) $r['can_view'], (int) $r['can_create'], (int) $r['can_edit'],
                     (int) $r['can_delete'], (int) $r['can_review'], (int) $r['can_approve']]);
}
echo '  Granted to ' . count($rows) . " role(s).\n";
