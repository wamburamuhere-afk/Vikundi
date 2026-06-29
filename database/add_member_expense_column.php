<?php
/**
 * database/add_member_expense_column.php
 * --------------------------------------
 * Expenses: an expense can be either whole-organization (today's behaviour) or
 * charged to one particular member. Adds the optional member link:
 *
 *   general_expenses.member_id INT NULL  — NULL = whole-org expense;
 *                                          set = that customer's expense.
 *
 * Idempotent and safe to re-run (checks information_schema first). Registered in
 * database/migrate.php, which the deploy workflow runs.
 *
 * Run manually:  php database/add_member_expense_column.php
 */

require_once __DIR__ . '/../includes/config.php';

$map = ['general_expenses' => [
    'member_id' => 'INT NULL',
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

echo "Member-expense column sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n")
    : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
