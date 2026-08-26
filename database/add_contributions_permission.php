<?php
/**
 * database/add_contributions_permission.php
 * -----------------------------------------
 * Register the `manage_contributions` permission page-key.
 *
 * THE KEY WAS NEVER IN THE TABLE. app/bms/customer/manage_contributions.php has
 * always opened with requireViewPermission('manage_contributions'), and
 * api/review_contribution.php and api/approve_contribution.php gate on
 * canReview()/canApprove() of the same key — but no row for it exists in
 * `permissions`. The effect: every one of those checks resolves to false for
 * anyone not caught by the isAdmin() bypass, so the contributions module is
 * reachable only by role NAME. A role renamed in Settings silently loses the
 * whole module, and a plain Member cannot see their own contributions at all.
 *
 * That was invisible while only the web app existed, because the officers are
 * all admin-by-name. The mobile API is not name-based (vk_api_is_admin() honours
 * role ids only, deliberately — see SEC-015), so without this row a Secretary
 * would be refused a review the web lets them do, and /auth/me would under-report
 * what the server will actually enforce. Fixing the data is the honest fix;
 * gating the API on role names instead would re-create the bug /auth/me was just
 * repaired for.
 *
 * THE GRANTS ARE MIRRORED FROM `expenses`, NOT HARDCODED. Expenses is the same
 * shape — member-visible money records with a pending/reviewed/approved workflow
 * — and each deployment has already curated who may do what to it. Copying that
 * row set means the GROUP's own decision carries over, rather than my idea of
 * which role ids exist; role ids differ between installs (Member is 15 live, 13
 * on a fresh schema). Where `expenses` is absent the leadership name list is the
 * fallback, matching add_fines_status_and_permission.php.
 *
 * Existing rows are never overwritten: if an admin has already tuned this key,
 * re-running the migration leaves their choice alone.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php BEFORE
 * seed_vicoba_roles.php so the key exists when roles are (re)seeded.
 *
 * Run manually:  php database/add_contributions_permission.php
 */

require_once __DIR__ . '/../includes/config.php';

const VK_CONTRIB_KEY   = 'manage_contributions';
const VK_CONTRIB_MODEL = 'expenses';

// 1. The permission row itself.
$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$check->execute([VK_CONTRIB_KEY]);
$permId = (int) ($check->fetchColumn() ?: 0);

if ($permId === 0) {
    $pdo->prepare(
        'INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        '',
        VK_CONTRIB_KEY,
        'Contributions',
        'View and manage member contributions',
        'Finance',
    ]);
    $permId = (int) $pdo->lastInsertId();
    echo "  Added '" . VK_CONTRIB_KEY . "' permission.\n";
} else {
    echo "  '" . VK_CONTRIB_KEY . "' permission already present.\n";
}

// 2. The grants, mirrored from the model key.
$check->execute([VK_CONTRIB_MODEL]);
$modelId = (int) ($check->fetchColumn() ?: 0);

$rows = [];
if ($modelId > 0) {
    $st = $pdo->prepare(
        'SELECT role_id, can_view, can_create, can_edit, can_delete, can_review, can_approve
           FROM role_permissions WHERE permission_id = ?'
    );
    $st->execute([$modelId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo '  Mirroring ' . count($rows) . " grant(s) from '" . VK_CONTRIB_MODEL . "'.\n";
}

if (!$rows) {
    // No model to copy: fall back to the leadership names, full rights.
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
    echo '  No ' . VK_CONTRIB_MODEL . " key to mirror — granting to " . count($rows) . " leadership role(s).\n";
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
        $kept++; // already configured — an admin's choice is not overwritten
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
echo "Contributions permission sync complete.\n";
