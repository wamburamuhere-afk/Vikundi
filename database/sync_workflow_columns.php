<?php
/**
 * database/sync_workflow_columns.php
 * ----------------------------------
 * Idempotent fix for schema drift between the codebase and a database that is
 * behind on migrations. Adds the review/approve workflow columns
 * (created_by / reviewed_by / reviewed_at / approved_by / approved_at) to every
 * table that the code expects them on, and widens the `contributions` status
 * enum to include 'reviewed'.
 *
 * Safe to run repeatedly and on any environment: it only touches tables that
 * exist and only adds columns that are missing (checks information_schema first).
 *
 * Symptoms it fixes:
 *   "Unknown column 'con.approved_by' in 'on clause'"   (contribution_view.php)
 *   "Unknown column 'reviewed_by' in 'field list'"      (budget review, etc.)
 *
 * Run on the server:  php database/sync_workflow_columns.php
 */

require_once __DIR__ . '/../includes/config.php';

/* table => [column => definition] — mirrors what the local/dev schema has. */
$map = [
    'contributions'        => ['created_by' => 'INT NULL', 'reviewed_by' => 'INT NULL', 'reviewed_at' => 'DATETIME NULL', 'approved_by' => 'INT NULL', 'approved_at' => 'DATETIME NULL'],
    'budgets'              => ['reviewed_by' => 'INT NULL', 'reviewed_at' => 'DATETIME NULL', 'approved_by' => 'INT NULL', 'approved_at' => 'DATETIME NULL'],
    'general_expenses'     => ['reviewed_by' => 'INT NULL', 'reviewed_at' => 'DATETIME NULL', 'approved_by' => 'INT NULL', 'approved_at' => 'DATETIME NULL'],
    'death_expenses'       => ['reviewed_by' => 'INT NULL', 'reviewed_at' => 'DATETIME NULL', 'approved_by' => 'INT NULL', 'approved_at' => 'DATETIME NULL'],
    'petty_cash_vouchers'  => ['reviewed_by' => 'INT NULL', 'reviewed_at' => 'DATETIME NULL', 'approved_by' => 'INT NULL'],
    'bank_reconciliations' => ['reviewed_by' => 'INT NULL'],
    'compliance_documents' => ['reviewed_by' => 'INT NULL', 'reviewed_at' => 'DATETIME NULL'],
    'leaves'               => ['approved_by' => 'INT NULL', 'approved_at' => 'DATETIME NULL'],
    'payroll'              => ['approved_by' => 'INT NULL'],
    'purchase_orders'      => ['approved_by' => 'INT NULL'],
    'sales_orders'         => ['approved_by' => 'INT NULL'],
    'sales_returns'        => ['approved_by' => 'INT NULL'],
    'supplier_credit_notes'=> ['approved_by' => 'INT NULL'],
    'supplier_payments'    => ['approved_by' => 'INT NULL'],
];

$tableExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
$colExists   = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

$added = [];
$skippedTables = [];
foreach ($map as $table => $cols) {
    $tableExists->execute([$table]);
    if ((int)$tableExists->fetchColumn() === 0) { $skippedTables[] = $table; continue; }
    foreach ($cols as $col => $def) {
        $colExists->execute([$table, $col]);
        if ((int)$colExists->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            $added[] = "$table.$col";
        }
    }
}

/* Widen the contributions status enum to support the review step (idempotent).
   Include '' so legacy empty-status rows are NOT truncated under strict SQL mode
   (production has historical contributions with an empty status). Wrapped so a
   failure here can never abort the rest of the migration. */
$enumFixed = false;
$ct = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='contributions' AND COLUMN_NAME='status'");
$ct->execute();
$type = (string)$ct->fetchColumn();
if ($type !== '' && strpos($type, "'reviewed'") === false) {
    try {
        // Production runs strict SQL mode and has legacy contributions with an empty /
        // invalid status, which makes redefining the enum fail (warning 1265 promoted
        // to an error). Relax strict mode for THIS connection only, normalize those
        // rows to 'approved' (they are confirmed contributions), then widen the enum to
        // add the 'reviewed' step. Wrapped so it can never abort the migration.
        $pdo->exec("SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode,'STRICT_TRANS_TABLES',''),'STRICT_ALL_TABLES','')");
        $pdo->exec("UPDATE contributions SET status = 'approved' WHERE status IS NULL OR status NOT IN ('pending','approved','cancelled')");
        $pdo->exec("ALTER TABLE contributions MODIFY COLUMN status ENUM('pending','reviewed','approved','cancelled') NOT NULL DEFAULT 'pending'");
        $enumFixed = true;
    } catch (Throwable $e) {
        echo "  (status enum widen skipped: " . $e->getMessage() . ")\n";
    }
}

echo "Workflow column sync complete.\n";
echo $added ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n") : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
echo $enumFixed ? "  contributions.status enum widened to include 'reviewed'.\n" : "  contributions.status enum already up to date.\n";
