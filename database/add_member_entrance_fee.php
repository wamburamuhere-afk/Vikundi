<?php
/**
 * database/add_member_entrance_fee.php
 * ------------------------------------
 * A member's own entrance fee (includes/contribution_standing.php, cs_entrance_for()):
 *
 *   customers.entrance_fee DECIMAL(15,2) NULL — NULL means "use the group's entrance fee",
 *                                               so every existing member keeps today's rule.
 *
 * The companion switch is a group setting, not a column: group_settings
 * 'mkoba_pays_entrance' = '1' takes the entrance fee out of M-Koba money first.
 * Absent (the default) leaves every group exactly as before.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_member_entrance_fee.php
 */

require_once __DIR__ . '/../includes/config.php';

$tableExists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'")->fetchColumn();
if ((int) $tableExists === 0) {
    echo "Member entrance fee: skipped (no customers table here).\n";
    return;
}

$col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'entrance_fee'")->fetchColumn();
if ((int) $col === 0) {
    $pdo->exec("ALTER TABLE `customers` ADD COLUMN `entrance_fee` DECIMAL(15,2) NULL DEFAULT NULL");
    echo "Member entrance fee: added customers.entrance_fee.\n";
} else {
    echo "Member entrance fee: no changes — already in sync.\n";
}
