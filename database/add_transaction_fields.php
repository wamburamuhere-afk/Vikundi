<?php
/**
 * database/add_transaction_fields.php
 * -----------------------------------
 * Finance: the Transactions recording form (and the importers) capture a
 * receipt number and the account/source the money landed in.
 *
 * Adds to `contributions`:
 *   receipt_number VARCHAR(100) NULL  — canonical receipt number (manual + M-Koba)
 *   account        VARCHAR(50)  NULL  — M-Koba / Bank / Cash / Mobile Money
 *
 * Idempotent and safe to re-run (checks information_schema first). Registered in
 * database/migrate.php, which the deploy workflow runs.
 *
 * Run manually:  php database/add_transaction_fields.php
 */

require_once __DIR__ . '/../includes/config.php';

$map = ['contributions' => [
    'receipt_number' => 'VARCHAR(100) NULL',
    'account'        => 'VARCHAR(50) NULL',
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

echo "Transaction fields sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n")
    : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
