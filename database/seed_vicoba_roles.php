<?php
/**
 * database/seed_vicoba_roles.php
 * ------------------------------
 * Establishes the four VICOBA system roles and removes the unused BMS roles.
 *
 *   Chairperson — full administrative access (via isAdmin()).
 *   Secretary   — full CRUD on operational data, NOT user/role/settings.
 *   Treasurer   — full CRUD on operational data, NOT user/role/settings.
 *   Member      — view-only on most pages (its limited-view masking lives elsewhere).
 *
 * Roles are resolved BY NAME, not by a fixed id: if a role with the name already
 * exists (e.g. a site already has a "Member" role), that existing role is reused.
 * A role is only created when its name is absent — at its preferred id if free,
 * otherwise at an auto-assigned id. This avoids the unique-name / foreign-key
 * clash that a fixed-id INSERT caused on sites that already had these roles.
 *
 * The default permission policy itself lives in includes/role_grants.php (pure,
 * unit-tested by RoleGrantsTest). The Member role gets view-only on every page
 * except an admin/action hide-list, so it can see most of the system read-only.
 *
 * Idempotent and deploy-safe (registered in database/migrate.php):
 *  - BMS leftover roles (by name) are removed; their users move to Member first.
 *  - The four roles are created/kept as needed.
 *  - Leadership permissions are seeded only when a role has none yet (manual UI
 *    changes survive a deploy); Member is enforced (reset to defaults every run).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/role_grants.php'; // pure, testable grant policy

// name => [purpose, preferred_id, description, enforce_defaults]
// enforce_defaults=true resets the role's permissions to its defaults on every
// run — used for Member so it stays strictly view-only (required for the member
// sensitive-data masking to apply). false = seed only when the role has none yet,
// so manual changes to the leadership roles are preserved.
$roles = [
    'Chairperson' => ['admin',       2,  'Group chairperson — full administrative access.', false],
    'Secretary'   => ['operational', 3,  'Group secretary — full CRUD on operational data.', false],
    'Treasurer'   => ['operational', 4,  'Group treasurer/accountant — full CRUD on operational data.', false],
    'Member'      => ['view',        13, 'Ordinary member — view-only on most pages, with a limited view of other members.', true],
];

// BMS leftover role NAMES to remove (names are stable across environments; ids are not).
$bmsRoleNames = ['Director', 'CFO', 'Accountant', 'Credit Manager', 'Loan Manager'];

$pdo->beginTransaction();
try {
    // 1. Resolve each role by NAME; create only when absent.
    $findByName = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = LOWER(?) LIMIT 1");
    $idTaken    = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_id = ?");
    $insWithId  = $pdo->prepare("INSERT INTO roles (role_id, role_name, description) VALUES (?, ?, ?)");
    $insAuto    = $pdo->prepare("INSERT INTO roles (role_name, description) VALUES (?, ?)");

    $resolved = []; // name => role_id
    foreach ($roles as $name => [$purpose, $prefId, $desc]) {
        $findByName->execute([$name]);
        $existing = $findByName->fetchColumn();
        if ($existing !== false) {
            $resolved[$name] = (int) $existing; // reuse the existing role
            continue;
        }
        $idTaken->execute([$prefId]);
        if ((int) $idTaken->fetchColumn() === 0) {
            $insWithId->execute([$prefId, $name, $desc]);
            $resolved[$name] = $prefId;
        } else {
            $insAuto->execute([$name, $desc]);
            $resolved[$name] = (int) $pdo->lastInsertId();
        }
    }
    $memberId = $resolved['Member'];

    // 2. Remove BMS leftover roles (by name). Move any of their users to Member
    //    first, and never touch a role we just resolved above.
    $findBms  = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) = LOWER(?) LIMIT 1");
    $moveUsers = $pdo->prepare("UPDATE users SET role_id = ? WHERE role_id = ?");
    $delPerms  = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
    $delRole   = $pdo->prepare("DELETE FROM roles WHERE role_id = ?");
    $removedBms = [];
    foreach ($bmsRoleNames as $bn) {
        $findBms->execute([$bn]);
        $bid = $findBms->fetchColumn();
        if ($bid === false) continue;
        $bid = (int) $bid;
        if (in_array($bid, $resolved, true)) continue; // safety: it's one of our roles
        $moveUsers->execute([$memberId, $bid]);
        $delPerms->execute([$bid]);
        $delRole->execute([$bid]);
        $removedBms[] = "$bn#$bid";
    }

    // 3. Seed default permissions. Leadership roles seed only when empty; Member
    //    (enforce=true) is reset to its view-only defaults on every run.
    $perms      = $pdo->query("SELECT permission_id, page_key FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
    $countPerms = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ?");
    $insPerm    = $pdo->prepare(
        "INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $resetPerms = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
    $seeded = [];
    $reset  = [];
    foreach ($roles as $name => [$purpose, , , $enforce]) {
        $rid = $resolved[$name];
        $countPerms->execute([$rid]);
        $has = (int) $countPerms->fetchColumn() > 0;
        if ($has && !$enforce) { continue; }                 // keep existing customisations
        if ($has && $enforce)  { $resetPerms->execute([$rid]); $reset[] = "$name#$rid"; }
        else                   { $seeded[] = "$name#$rid"; }
        foreach ($perms as $pid => $key) {
            $g = vk_role_grants($purpose, $key);
            if ($g === null) { continue; }
            $insPerm->execute([$rid, $pid, $g[0], $g[1], $g[2], $g[3], $g[4], $g[5]]);
        }
    }

    $pdo->commit();

    $list = [];
    foreach ($resolved as $n => $i) { $list[] = "$n#$i"; }
    echo "VICOBA roles ready: " . implode(', ', $list) . ".\n";
    echo $removedBms ? "  Removed BMS roles: " . implode(', ', $removedBms) . "\n" : "  No BMS leftover roles to remove.\n";
    if ($seeded) echo "  Seeded default permissions for: " . implode(', ', $seeded) . "\n";
    if ($reset)  echo "  Reset to default permissions (enforced): " . implode(', ', $reset) . "\n";
    if (!$seeded && !$reset) echo "  Roles already configured — left unchanged.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "VICOBA role seed FAILED: " . $e->getMessage() . "\n";
    throw $e;
}
