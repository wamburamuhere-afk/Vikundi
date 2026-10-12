<?php
/**
 * database/create_vicoba_dividend_tables.php
 * ------------------------------------------
 * Year-end dividends (includes/dividends.php):
 *
 *   vicoba_dividend_runs   one distribution: its period, the frozen figures, how
 *                          it is paid and where it has got to
 *   vicoba_dividend_lines  one member's part of a run
 *
 * and the `dividends` permission page-key, granted to leadership (Admin,
 * Chairperson, Secretary, Treasurer). Members are not granted it — they see
 * their own dividend on Member Home.
 *
 * Existing grants are never overwritten. Idempotent and safe to re-run.
 * Registered in database/migrate.php BEFORE seed_vicoba_roles.php so the
 * permission exists when roles are (re)seeded.
 *
 * Run manually:  php database/create_vicoba_dividend_tables.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vicoba_dividend_runs` (
      `id` int NOT NULL AUTO_INCREMENT,
      `reference` varchar(30) NOT NULL,
      `period_from` date NOT NULL,
      `period_to` date NOT NULL,
      `interest_income` decimal(15,2) NOT NULL DEFAULT 0.00,
      `penalty_income` decimal(15,2) NOT NULL DEFAULT 0.00,
      `fines_income` decimal(15,2) NOT NULL DEFAULT 0.00,
      `expenses` decimal(15,2) NOT NULL DEFAULT 0.00,
      `surplus` decimal(15,2) NOT NULL DEFAULT 0.00,
      `distribute_pct` decimal(5,2) NOT NULL DEFAULT 100.00,
      `distributable` decimal(15,2) NOT NULL DEFAULT 0.00,
      `total_share_capital` decimal(15,2) NOT NULL DEFAULT 0.00,
      `method` enum('cash','shares') NOT NULL DEFAULT 'cash',
      `status` enum('confirmed','distributed','cancelled') NOT NULL DEFAULT 'confirmed',
      `confirmed_by` int DEFAULT NULL,
      `confirmed_at` datetime DEFAULT NULL,
      `distributed_by` int DEFAULT NULL,
      `distributed_at` datetime DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_vdr_reference` (`reference`),
      KEY `idx_vdr_period` (`period_from`, `period_to`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vicoba_dividend_lines` (
      `id` int NOT NULL AUTO_INCREMENT,
      `run_id` int NOT NULL,
      `customer_id` int NOT NULL,
      `share_capital` decimal(15,2) NOT NULL DEFAULT 0.00,
      `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
      `contribution_id` int DEFAULT NULL,   -- set when the dividend was added to shares
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_vdl_run_member` (`run_id`, `customer_id`),
      KEY `idx_vdl_member` (`customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  vicoba dividend tables ready (runs, lines).\n";

// The permission row.
$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$check->execute(['dividends']);
$permId = (int) ($check->fetchColumn() ?: 0);
if ($permId === 0) {
    $pdo->prepare('INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?, ?, ?, ?, ?)')
        ->execute(['', 'dividends', 'Dividends', 'Calculate and distribute year-end dividends', 'Finance']);
    $permId = (int) $pdo->lastInsertId();
    echo "  Added 'dividends' permission.\n";
} else {
    echo "  'dividends' permission already present.\n";
}

// Grants: full leadership. Admin and Chairperson are bypassed by isAdmin()
// anyway; they are granted for row completeness, like every other permission.
$roleNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
              'secretary', 'sekretari', 'katibu', 'treasurer', 'mweka hazina'];
$in = implode(',', array_fill(0, count($roleNames), '?'));
$roleIds = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$roleIds->execute($roleNames);

$has   = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?');
$grant = $pdo->prepare(
    'INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
     VALUES (?, ?, 1, 1, 1, 1, 1, 1)'
);
$added = 0;
foreach ($roleIds->fetchAll(PDO::FETCH_COLUMN) as $rid) {
    $has->execute([$rid, $permId]);
    if ((int) $has->fetchColumn() === 0) {
        $grant->execute([$rid, $permId]);
        $added++;
    }
}
echo "  Granted 'dividends' to $added leadership role(s).\n";
