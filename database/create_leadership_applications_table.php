<?php
/**
 * database/create_leadership_applications_table.php
 * -------------------------------------------------
 * Leadership applications — the stage that was missing between "the group needs
 * new officers" and "members vote".
 *
 *   member applies  ->  Committee reviews  ->  approved names become the ballot
 *
 * An election is an existing `votes` row of type 'candidate'. While it is still
 * `draft` it accepts applications; opening it starts the voting and closes
 * applications. That reuses the voting module's own lifecycle rather than adding a
 * second one beside it, so there is no way for the two to disagree about whether
 * nominations are open.
 *
 * Also:
 *   - registers `leadership_applications` (members apply) and
 *     `manage_leadership_applications` (Committee reviews) as permission page-keys
 *   - seeds the editable list of positions into group_settings
 *   - widens group_settings.setting_value, which was varchar(255) — see below
 *
 * Idempotent. Registered in database/migrate.php.
 *
 * Run manually:  php database/create_leadership_applications_table.php
 */

require_once __DIR__ . '/../includes/config.php';

// --- The applications table ---------------------------------------------------
// The UNIQUE key is the rule the group set — one application per member per
// election — enforced by the database rather than by a check in PHP that a second
// browser tab can race past. Withdrawing sets status='withdrawn' and re-applying
// updates that same row, so the constraint stays true without blocking anyone.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `leadership_applications` (
      `id` int NOT NULL AUTO_INCREMENT,
      `vote_id` int NOT NULL,
      `member_id` int NOT NULL,
      `position` varchar(120) NOT NULL,
      `statement` text,
      `experience` text,
      `proposer_member_id` int DEFAULT NULL,
      `declaration` tinyint(1) NOT NULL DEFAULT 0,
      `status` enum('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
      `review_note` varchar(500) DEFAULT NULL,
      `reviewed_by` int DEFAULT NULL,
      `reviewed_at` datetime DEFAULT NULL,
      `vote_option_id` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `one_application_per_member_per_election` (`vote_id`,`member_id`),
      KEY `election_status` (`vote_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
echo "  leadership_applications table ready.\n";

// --- group_settings.setting_value: varchar(255) -> TEXT ------------------------
// The positions list is stored here. Six bilingual positions are already ~171
// characters, leaving room for two or three more. A group adding a seventh would
// hit the ceiling, and under a non-strict sql_mode MySQL truncates silently — a
// position would simply vanish from the application form with no error anywhere.
// Guarded so the ALTER runs once rather than on every deploy.
$col = $pdo->query("
    SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_settings'
       AND COLUMN_NAME = 'setting_value'
")->fetchColumn();
if ($col && strtolower($col) !== 'text') {
    $pdo->exec("ALTER TABLE `group_settings` MODIFY `setting_value` TEXT NULL");
    echo "  Widened group_settings.setting_value to TEXT.\n";
}

// --- The positions the group elects -------------------------------------------
// Editable rather than hard-coded, so renaming an office never needs a release.
// One per line; each carries both languages because the product is bilingual and
// the list is shown as written.
$defaultPositions = implode("\n", [
    'Chairperson / Mwenyekiti',
    'Vice Chairperson / Makamu Mwenyekiti',
    'Secretary / Katibu',
    'Assistant Secretary / Katibu Msaidizi',
    'Treasurer / Mweka Hazina',
    'Committee Member / Mjumbe',
]);
$has = $pdo->prepare("SELECT COUNT(*) FROM group_settings WHERE setting_key = 'leadership_positions'");
$has->execute();
if ((int) $has->fetchColumn() === 0) {
    $pdo->prepare("INSERT INTO group_settings (setting_key, setting_value, description) VALUES (?,?,?)")
        ->execute([
            'leadership_positions',
            $defaultPositions,
            'Positions members may apply for in a leadership election. One per line.',
        ]);
    echo "  Seeded default leadership positions.\n";
}

// --- Permission page-keys -----------------------------------------------------
$permCheck = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$permIns   = $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?,?,?,?,?)");
$keys = [
    ['leadership_applications',        'Leadership Applications',        'Apply for a leadership position'],
    ['manage_leadership_applications', 'Review Leadership Applications', 'Review and approve applications for leadership'],
];
foreach ($keys as [$key, $name, $desc]) {
    $permCheck->execute([$key]);
    if (!$permCheck->fetchColumn()) {
        $permIns->execute(['', $key, $name, $desc, 'Management']);
        echo "  Added '$key' permission.\n";
    }
}

/**
 * Grant a page-key to a set of roles, RAISING the rights on a row that already
 * exists rather than skipping it.
 *
 * Skipping was wrong, and it failed in a way that only appeared on the second
 * deploy. seed_vicoba_roles.php runs earlier in the migration list and resets the
 * Member role to view-only on EVERY run — that is deliberate, Member is meant to
 * be view-only almost everywhere. On the first deploy this permission did not
 * exist yet, so the seeder could not touch it and the grant landed with create=1.
 * On the next deploy the seeder re-seeded Member across every page including this
 * new one, view-only; an insert-if-missing grant then saw a row and did nothing.
 * A member could open the application page and not submit it.
 *
 * Applying for leadership is a deliberate exception to "Member is view-only", so
 * the grant has to be re-asserted after each reseed.
 *
 * Rights are raised, never lowered: an administrator who has widened a leadership
 * role's access through the Roles screen keeps it.
 */
$grantTo = function (string $pageKey, array $roleNames, array $rights) use ($pdo, $permCheck): void {
    $permCheck->execute([$pageKey]);
    $pid = $permCheck->fetchColumn();
    if (!$pid) {
        return;
    }
    $in  = implode(',', array_fill(0, count($roleNames), '?'));
    $ids = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
    $ids->execute(array_map('strtolower', $roleNames));

    $has   = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $grant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?,?,?,?,?,?)");
    $raise = $pdo->prepare("
        UPDATE role_permissions
           SET can_view   = GREATEST(can_view,   ?),
               can_create = GREATEST(can_create, ?),
               can_edit   = GREATEST(can_edit,   ?),
               can_delete = GREATEST(can_delete, ?)
         WHERE role_id = ? AND permission_id = ?");
    $added = $raised = 0;
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $has->execute([$rid, $pid]);
        if ((int) $has->fetchColumn() === 0) {
            $grant->execute([$rid, $pid, $rights[0], $rights[1], $rights[2], $rights[3]]);
            $added++;
        } else {
            $raise->execute([$rights[0], $rights[1], $rights[2], $rights[3], $rid, $pid]);
            $raised += $raise->rowCount() > 0 ? 1 : 0;
        }
    }
    echo "  Granted $pageKey to $added role(s), re-asserted on $raised.\n";
};

$leadership = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
               'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];
$everyone   = array_merge($leadership, ['member', 'mwanachama']);

// Applying: everyone, including ordinary members — they are the applicants.
// view + create only; an application cannot be edited or deleted once submitted,
// it is withdrawn, which leaves the record intact for the audit trail.
$grantTo('leadership_applications', $everyone, [1, 1, 0, 0]);

// Reviewing: the Committee only. This is the line the group drew — members apply
// and vote, they do not approve.
$grantTo('manage_leadership_applications', $leadership, [1, 1, 1, 0]);

// Applications whose election has been deleted. Before actions/delete_vote.php
// learned about this table it removed the vote and left these behind, pointing at
// an id that no longer exists. Idempotent: after the first sweep there are none.
$orphans = $pdo->exec("
    DELETE a FROM leadership_applications a
      LEFT JOIN votes v ON v.id = a.vote_id
     WHERE v.id IS NULL");
if ($orphans) {
    echo "  Removed $orphans application(s) whose election no longer exists.\n";
}

echo "Leadership applications ready.\n";
