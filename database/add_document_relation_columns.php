<?php
/**
 * database/add_document_relation_columns.php
 * ------------------------------------------
 * Documents (the Library) can be attached to a source record. Until now the only
 * link was free text in the description ("Expense ID: 42" / "Member ID: 7"),
 * which is fragile and cannot reliably tie a receipt to one death record. Adds a
 * structured link:
 *
 *   documents.related_type VARCHAR(40) NULL  — e.g. 'general_expense','death_expense'
 *   documents.related_id   INT NULL          — the id of that record
 *
 * Idempotent and safe to re-run (checks information_schema first). Registered in
 * database/migrate.php, which the deploy workflow runs.
 *
 * Run manually:  php database/add_document_relation_columns.php
 */

require_once __DIR__ . '/../includes/config.php';

$map = ['documents' => [
    'related_type' => 'VARCHAR(40) NULL',
    'related_id'   => 'INT NULL',
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

echo "Document relation columns sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n")
    : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
