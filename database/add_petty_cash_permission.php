<?php
/**
 * database/add_petty_cash_permission.php
 * ---------------------------------------
 * Register the `petty_cash` permission page-key and grant it to the same
 * roles, with the same rights, as `expenses`.
 *
 * THE KEY WAS NEVER IN THE TABLE. Found while building Module 9 (Expenses &
 * Petty Cash) of the mobile API. The web's own petty-cash files already
 * gate on this exact key — api/review_petty_cash.php's canReview('petty_cash'),
 * actions/approve_petty_cash.php's canApprove('petty_cash'),
 * actions/delete_petty_cash.php's requirePermissionJson('delete','petty_cash')
 * — but no row for it exists in `permissions`, so every one of those checks
 * has always resolved false for anyone not caught by the isAdmin() bypass
 * (Admin/Chairperson only). A Secretary or Treasurer reviewing or approving a
 * petty-cash voucher on the web has been silently refused.
 *
 * THE WEB'S OWN PERMISSION GATING FOR PETTY CASH IS INCONSISTENT ACROSS
 * FILES — actions/save_petty_cash.php's create path checks `expenses`
 * instead; the list/detail pages (petty_cash.php, petty_cash_view.php) use a
 * hardcoded role-name array, not RBAC at all; and actions/fetch_petty_cash.php
 * (the list's own AJAX endpoint) has NO permission check beyond being logged
 * in — any authenticated Member could pull the whole voucher list. This
 * migration does not touch any of those web files; the mobile API normalizes
 * on this ONE key throughout (todo.md's judgment call #3), and
 * actions/fetch_petty_cash.php's hole is closed in the same change that adds
 * this migration.
 *
 * THE GRANTS ARE MIRRORED FROM `expenses`, NOT HARDCODED — same shape as
 * add_contributions_permission.php's own reasoning: a member-visible money
 * record with a pending/reviewed/approved/paid workflow, and `expenses`
 * already carries a deployment's own curated view of who may do what to that
 * shape of record. Where `expenses` is absent the leadership name list is
 * the fallback, matching add_contributions_permission.php.
 *
 * Existing rows are never overwritten.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_petty_cash_permission.php
 */

require_once __DIR__ . '/../includes/config.php';

const VK_PETTY_KEY   = 'petty_cash';
const VK_PETTY_MODEL = 'expenses';

// 1. The permission row itself.
$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$check->execute([VK_PETTY_KEY]);
$permId = (int) ($check->fetchColumn() ?: 0);

if ($permId === 0) {
    $pdo->prepare(
        'INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        '',
        VK_PETTY_KEY,
        'Petty Cash',
        'View and manage petty cash vouchers',
        'Finance',
    ]);
    $permId = (int) $pdo->lastInsertId();
    echo "  Added '" . VK_PETTY_KEY . "' permission.\n";
} else {
    echo "  '" . VK_PETTY_KEY . "' permission already present.\n";
}

// 2. The grants, mirrored from the model key.
$check->execute([VK_PETTY_MODEL]);
$modelId = (int) ($check->fetchColumn() ?: 0);

$rows = [];
if ($modelId > 0) {
    $st = $pdo->prepare(
        'SELECT role_id, can_view, can_create, can_edit, can_delete, can_review, can_approve
           FROM role_permissions WHERE permission_id = ?'
    );
    $st->execute([$modelId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo '  Mirroring ' . count($rows) . " grant(s) from '" . VK_PETTY_MODEL . "'.\n";
}

if (!$rows) {
    $leaderNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
                    'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];
    $in = implode(',', array_fill(0, count($leaderNames), '?'));
    $ids = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
    $ids->execute(array_map('strtolower', $leaderNames));
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $rows[] = [
            'role_id'    => $rid, 'can_view' => 1, 'can_create' => 1, 'can_edit' => 1,
            'can_delete' => 1,    'can_review' => 1, 'can_approve' => 1,
        ];
    }
    echo '  No ' . VK_PETTY_MODEL . " key to mirror — granting to " . count($rows) . " leadership role(s).\n";
}

$has = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?');
$grant = $pdo->prepare(
    'INSERT INTO role_permissions
        (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

$added = 0;
$kept  = 0;
foreach ($rows as $r) {
    $has->execute([$r['role_id'], $permId]);
    if ((int) $has->fetchColumn() > 0) {
        $kept++;
        continue;
    }
    $grant->execute([
        $r['role_id'], $permId,
        (int) $r['can_view'], (int) $r['can_create'], (int) $r['can_edit'],
        (int) $r['can_delete'], (int) $r['can_review'], (int) $r['can_approve'],
    ]);
    $added++;
}

echo "  Granted to $added role(s); left $kept existing grant(s) untouched.\n";
echo "Petty cash permission sync complete.\n";
