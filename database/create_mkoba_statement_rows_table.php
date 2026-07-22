<?php
/**
 * database/create_mkoba_statement_rows_table.php
 * ----------------------------------------------
 * M-Koba reconciliation — the "statement as received" side of the ledger.
 *
 * Stores a faithful mirror of every row of an imported M-Koba statement, exactly
 * as it appeared (S/No, Trans ID, Receipt, Date, Member Name, Member ID, Source,
 * Destination, Amount, Trans Type), tagged with what became of it in Vikundi:
 *   - imported : became a member contribution (linked via `contribution_id`)
 *   - excluded : not a member contribution (group transfer / account opening /
 *                blank row) — kept for the tie-out, never counted in the books
 *   - missing  : a contribution-type row with no matching member/contribution
 *                (a real discrepancy to investigate)
 *
 * The reconciliation view (app/constant/accounts/mkoba_reconciliation.php) reads
 * this and ties it out against `contributions` so a leader can hold the M-Koba
 * statement next to Vikundi and see every row — and every shilling — accounted.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/create_mkoba_statement_rows_table.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `mkoba_statement_rows` (
      `id` int NOT NULL AUTO_INCREMENT,
      `batch` varchar(150) DEFAULT NULL,          -- statement label (file + import time)
      `sno` varchar(20) DEFAULT NULL,             -- M-Koba 'NO'
      `trans_id` varchar(120) DEFAULT NULL,
      `receipt` varchar(120) DEFAULT NULL,
      `trans_date` date DEFAULT NULL,
      `member_name` varchar(191) DEFAULT NULL,
      `member_id` varchar(30) DEFAULT NULL,       -- phone as printed on the statement
      `source` varchar(30) DEFAULT NULL,
      `destination` varchar(30) DEFAULT NULL,
      `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
      `trans_type` varchar(120) DEFAULT NULL,
      `outcome` enum('imported','excluded','missing') NOT NULL DEFAULT 'excluded',
      `reason` varchar(191) DEFAULT NULL,
      `contribution_id` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_msr_batch` (`batch`),
      KEY `idx_msr_receipt` (`receipt`),
      KEY `idx_msr_outcome` (`outcome`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Register the permission page-key for the reconciliation view (idempotent).
$check = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$check->execute(['mkoba_reconciliation']);
if (!$check->fetchColumn()) {
    $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?,?,?,?,?)")
        ->execute(['', 'mkoba_reconciliation', 'M-Koba Reconciliation', 'Reconcile imported M-Koba statements against the transactions ledger', 'Finance']);
    echo "mkoba_statement_rows table ready. Added 'mkoba_reconciliation' permission.\n";
} else {
    echo "mkoba_statement_rows table ready. 'mkoba_reconciliation' permission already present.\n";
}
