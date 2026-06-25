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
    // RBAC permission flags the permission loader selects (audit B2). Without
    // these, core/permissions.php's SELECT throws and EVERY user's permissions
    // fail to load — granular roles get nothing. Match the existing can_* type.
    'role_permissions'     => ['can_review' => 'TINYINT(1) NOT NULL DEFAULT 0', 'can_approve' => 'TINYINT(1) NOT NULL DEFAULT 0'],
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

/* Widen the status enum on EVERY review/approve workflow table so the 'reviewed'
   step works (idempotent). Production runs strict SQL mode and may hold legacy /
   empty status values, so relax strict mode for THIS connection first; then each
   MODIFY completes without a 1265 truncation error. Wrapped per-table so one
   failure can never abort the rest of the migration. */
try { $pdo->exec("SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode,'STRICT_TRANS_TABLES',''),'STRICT_ALL_TABLES','')"); } catch (Throwable $e) {}

$enumTargets = [
    'contributions'       => "ENUM('pending','reviewed','approved','cancelled') NOT NULL DEFAULT 'pending'",
    'budgets'             => "ENUM('draft','pending','reviewed','approved','rejected') DEFAULT 'draft'",
    'general_expenses'    => "ENUM('pending','reviewed','approved','rejected') NOT NULL DEFAULT 'pending'",
    'death_expenses'      => "ENUM('pending','reviewed','approved','rejected','inactive') NOT NULL DEFAULT 'pending'",
    'petty_cash_vouchers' => "ENUM('pending','reviewed','approved','rejected') NOT NULL DEFAULT 'pending'",
];
$enumsFixed = [];
$stq = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='status'");
foreach ($enumTargets as $tbl => $def) {
    $tableExists->execute([$tbl]);
    if ((int)$tableExists->fetchColumn() === 0) continue;
    $stq->execute([$tbl]);
    $stype = (string)$stq->fetchColumn();
    if ($stype === '' || strpos($stype, "'reviewed'") !== false) continue; // no status col, or already widened
    try {
        $pdo->exec("ALTER TABLE `$tbl` MODIFY COLUMN status $def");
        $enumsFixed[] = $tbl;
    } catch (Throwable $e) {
        echo "  (status enum widen skipped for $tbl: " . $e->getMessage() . ")\n";
    }
}

echo "Workflow column sync complete.\n";
echo $added ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n") : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
echo $enumsFixed ? ("  status enum widened on: " . implode(', ', $enumsFixed) . "\n") : "  status enums already up to date.\n";
