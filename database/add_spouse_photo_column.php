<?php
/**
 * database/add_spouse_photo_column.php
 * ------------------------------------
 * Registration: optional passport photo for the member's spouse.
 *
 * Adds `customers.spouse_photo` (varchar path). Idempotent and safe to re-run
 * (checks information_schema first). Mirrors the parent/member photo columns.
 * Registered in database/migrate.php, which the deploy workflow runs.
 *
 * Run manually:  php database/add_spouse_photo_column.php
 */

require_once __DIR__ . '/../includes/config.php';

$map = ['customers' => ['spouse_photo' => 'VARCHAR(255) NULL']];

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

echo "Spouse photo column sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n")
    : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
