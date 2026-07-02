<?php
/**
 * database/add_registration_number.php
 * ------------------------------------
 * Members get an official Registration Number assigned by leadership (never
 * random). Adds the per-member field:
 *
 *   customers.registration_number VARCHAR(50) NULL
 *
 * Empty by default — which is exactly the self-registration state until an admin
 * assigns one at the preview/approval step.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_registration_number.php
 */

require_once __DIR__ . '/../includes/config.php';

$map = ['customers' => [
    'registration_number' => 'VARCHAR(50) NULL',
]];

$tableExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
$colExists   = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

$added = [];
$skippedTables = [];
foreach ($map as $table => $cols) {
    $tableExists->execute([$table]);
    if ((int) $tableExists->fetchColumn() === 0) { $skippedTables[] = $table; continue; }
    foreach ($cols as $col => $def) {
        $colExists->execute([$table, $col]);
        if ((int) $colExists->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            $added[] = "$table.$col";
        }
    }
}

echo "Member registration-number column sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n")
    : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
