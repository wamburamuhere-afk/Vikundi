<?php
/**
 * database/create_voting_tables.php
 * ---------------------------------
 * Voting module. A secret-ballot design: the member's CHOICE is stored with no
 * link to them, so no one — not even the admin — can see who voted for whom;
 * only the tally.
 *
 *   votes               — the poll (type, status, open/close window, publish flag)
 *   vote_options        — the choices (candidates or Yes/No/Abstain)
 *   vote_eligibility    — snapshot of who MAY vote (taken when the vote opens)
 *   vote_participation  — records THAT a member voted (blocks a second vote)
 *   vote_ballots        — the ANONYMOUS choice (no member_id — unlinkable)
 *
 * Also registers the `voting` (members vote) and `manage_voting` (leadership)
 * permission page-keys, and grants manage_voting to leadership directly so it
 * reaches Secretary/Treasurer on existing deployments.
 *
 * Idempotent. Registered in database/migrate.php BEFORE seed_vicoba_roles.php.
 *
 * Run manually:  php database/create_voting_tables.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `votes` (
      `id` int NOT NULL AUTO_INCREMENT,
      `title` varchar(255) NOT NULL,
      `description` text,
      `vote_type` enum('candidate','motion') NOT NULL DEFAULT 'candidate',
      `status` enum('draft','open','closed') NOT NULL DEFAULT 'draft',
      `opens_at` datetime DEFAULT NULL,
      `closes_at` datetime DEFAULT NULL,
      `publish_results` tinyint(1) NOT NULL DEFAULT 0,
      `created_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vote_options` (
      `id` int NOT NULL AUTO_INCREMENT,
      `vote_id` int NOT NULL,
      `label` varchar(255) NOT NULL,
      `member_id` int DEFAULT NULL,
      `position` int NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `vote_id` (`vote_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vote_eligibility` (
      `id` int NOT NULL AUTO_INCREMENT,
      `vote_id` int NOT NULL,
      `member_id` int NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `vote_member_elig` (`vote_id`,`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vote_participation` (
      `id` int NOT NULL AUTO_INCREMENT,
      `vote_id` int NOT NULL,
      `member_id` int NOT NULL,
      `voted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `vote_member_part` (`vote_id`,`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// The anonymous ballot — deliberately NO member_id, so a choice can never be
// traced back to a voter.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vote_ballots` (
      `id` int NOT NULL AUTO_INCREMENT,
      `vote_id` int NOT NULL,
      `option_id` int NOT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `vote_id` (`vote_id`),
      KEY `option_id` (`option_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// --- Permission page-keys -----------------------------------------------------
$permCheck = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$permIns   = $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?,?,?,?,?)");
$keys = [
    ['voting',        'Voting',        'Cast votes in open group polls'],
    ['manage_voting', 'Manage Voting', 'Create and run group votes; view results'],
];
foreach ($keys as [$key, $name, $desc]) {
    $permCheck->execute([$key]);
    if (!$permCheck->fetchColumn()) {
        $permIns->execute(['', $key, $name, $desc, 'Management']);
        echo "  Added '$key' permission.\n";
    }
}

// Grant manage_voting to leadership directly (new key never reaches
// Secretary/Treasurer via the seeder on existing DBs).
$permCheck->execute(['manage_voting']);
$mvId = $permCheck->fetchColumn();
if ($mvId) {
    $leaderNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
                    'secretary', 'sekretari', 'katibu', 'treasurer', 'mhazini', 'mweka hazina'];
    $in = implode(',', array_fill(0, count($leaderNames), '?'));
    $roleIds = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
    $roleIds->execute(array_map('strtolower', $leaderNames));
    $has   = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $grant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 1, 1, 1)");
    $granted = 0;
    foreach ($roleIds->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $has->execute([$rid, $mvId]);
        if ((int) $has->fetchColumn() === 0) { $grant->execute([$rid, $mvId]); $granted++; }
    }
    echo "  Granted manage_voting to $granted leadership role(s).\n";
}

echo "Voting tables + permissions ready.\n";
