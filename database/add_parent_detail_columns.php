<?php
/**
 * database/add_parent_detail_columns.php
 * --------------------------------------
 * Registration PR-B: richer parent details on the member record.
 *
 * Adds, for both father and mother, structured name + the same six-field
 * location used for the member's residence, plus an optional passport photo:
 *   {parent}_first_name / _middle_name / _last_name
 *   {parent}_country / _state / _district / _ward / _street / _house_number
 *   {parent}_photo
 *
 * The legacy columns (father_name, father_location, father_sub_location,
 * father_phone, and the mother_* equivalents) are kept and still populated by
 * the handlers, so existing records and reports are unaffected.
 *
 * Idempotent and safe to re-run on any environment: only missing columns are
 * added (checks information_schema first). Mirrors the existing nok_* columns.
 *
 * Run on the server:  php database/add_parent_detail_columns.php
 */

require_once __DIR__ . '/../includes/config.php';

$parentCols = [];
foreach (['father', 'mother'] as $p) {
    $parentCols["{$p}_first_name"]   = 'VARCHAR(100) NULL';
    $parentCols["{$p}_middle_name"]  = 'VARCHAR(100) NULL';
    $parentCols["{$p}_last_name"]    = 'VARCHAR(100) NULL';
    $parentCols["{$p}_country"]      = 'VARCHAR(100) NULL';
    $parentCols["{$p}_state"]        = 'VARCHAR(100) NULL';
    $parentCols["{$p}_district"]     = 'VARCHAR(100) NULL';
    $parentCols["{$p}_ward"]         = 'VARCHAR(100) NULL';
    $parentCols["{$p}_street"]       = 'VARCHAR(150) NULL';
    $parentCols["{$p}_house_number"] = 'VARCHAR(50) NULL';
    $parentCols["{$p}_photo"]        = 'VARCHAR(255) NULL';
}

$map = ['customers' => $parentCols];

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

echo "Parent detail column sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n")
    : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
