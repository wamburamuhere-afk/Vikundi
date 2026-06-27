<?php
/**
 * database/seed_vicoba_roles.php
 * ------------------------------
 * Establishes the four VICOBA system roles and removes the unused BMS roles.
 *
 *   2  Chairperson — full administrative access (via isAdmin()).
 *   3  Secretary   — full CRUD on operational data, NOT user/role/settings.
 *   4  Treasurer   — full CRUD on operational data, NOT user/role/settings.
 *   13 Member      — view-only (its limited-view masking lives in PR-2).
 *
 * Removed: Director (5), CFO (6), Accountant (7), Credit Manager (8),
 * Loan Manager (9) — BMS leftovers. Any user on those roles is reassigned to
 * Member first so nobody is orphaned.
 *
 * Idempotent and deploy-safe (registered in database/migrate.php):
 *  - BMS roles are removed on every run.
 *  - The four roles are created/renamed if needed.
 *  - Default permissions are seeded only when a role has none yet, so manual
 *    permission changes made later in the UI are never wiped on deploy.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/role_grants.php'; // pure, testable grant rules

$bmsRoleIds = [5, 6, 7, 8, 9];

$roles = [
    2  => ['Chairperson', 'Group chairperson — full administrative access.'],
    3  => ['Secretary',   'Group secretary — full CRUD on operational data.'],
    4  => ['Treasurer',   'Group treasurer/accountant — full CRUD on operational data.'],
    13 => ['Member',      'Ordinary member — view-only on most pages, with a limited view of other members.'],
];

// Member (role 13) is a FIXED view-only system role, so its permissions are
// re-synced on every run (self-healing across deploys). The other roles keep
// the "seed only when empty" guard so manual UI tweaks survive a deploy.
$resyncEveryRun = [13];

$pdo->beginTransaction();
try {
    // 1. Reassign any users on a BMS role to Member, then drop the BMS roles.
    $in = implode(',', array_map('intval', $bmsRoleIds));
    $pdo->exec("UPDATE users SET role_id = 13 WHERE role_id IN ($in)");
    $pdo->exec("DELETE FROM role_permissions WHERE role_id IN ($in)");
    $pdo->exec("DELETE FROM roles WHERE role_id IN ($in)");

    // 2. Create / refresh the four roles.
    $upsert = $pdo->prepare(
        "INSERT INTO roles (role_id, role_name, description) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), description = VALUES(description)"
    );
    foreach ($roles as $id => [$name, $desc]) {
        $upsert->execute([$id, $name, $desc]);
    }

    // 3. Seed default permissions — only for a role that has none yet.
    $perms  = $pdo->query("SELECT permission_id, page_key FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
    $count  = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ?");
    $insert = $pdo->prepare(
        "INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $delete = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");

    $seeded = [];
    foreach (array_keys($roles) as $roleId) {
        if (in_array($roleId, $resyncEveryRun, true)) {
            $delete->execute([$roleId]); // fixed system role — always re-sync
        } else {
            $count->execute([$roleId]);
            if ((int) $count->fetchColumn() > 0) { continue; } // already configured — leave it
        }
        foreach ($perms as $pid => $key) {
            $g = vk_role_grants($roleId, $key);
            if ($g === null) { continue; }
            $insert->execute([$roleId, $pid, $g[0], $g[1], $g[2], $g[3], $g[4], $g[5]]);
        }
        $seeded[] = $roleId;
    }

    $pdo->commit();
    echo "VICOBA roles ready (Chairperson, Secretary, Treasurer, Member); BMS roles removed.\n";
    echo $seeded
        ? "  Seeded default permissions for role id(s): " . implode(', ', $seeded) . "\n"
        : "  All four roles already had permissions — left unchanged.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "VICOBA role seed FAILED: " . $e->getMessage() . "\n";
    throw $e;
}
