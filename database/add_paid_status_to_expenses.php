<?php
/**
 * database/add_paid_status_to_expenses.php
 * ----------------------------------------
 * "Approved" is not "paid". An expense can be authorised by the committee and yet
 * the money hasn't actually left the account. This adds a `paid` state (and
 * paid_at / paid_by) to the three expense workflows so the treasurer can record
 * when the money was really disbursed — the first step toward a cash-basis
 * balance (see the follow-up that flips getGroupFundBalance to count only paid).
 *
 * PR 1 is deliberately balance-neutral: getGroupFundBalance now counts an expense
 * whether it is `approved` OR `paid`, so adding this state doesn't move the books.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 */

require_once __DIR__ . '/../includes/config.php';

$tables = ['death_expenses', 'general_expenses', 'petty_cash_vouchers'];

$colInfo = $pdo->prepare("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                            FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
$colExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
$tblExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");

foreach ($tables as $table) {
    $tblExists->execute([$table]);
    if ((int) $tblExists->fetchColumn() === 0) { echo "  `$table` not present — skipped.\n"; continue; }

    // 1. Add 'paid' to the status enum (preserving nullability + default).
    $colInfo->execute([$table, 'status']);
    $info = $colInfo->fetch(PDO::FETCH_ASSOC);
    if ($info && stripos($info['COLUMN_TYPE'], "'paid'") === false && stripos($info['COLUMN_TYPE'], 'enum(') === 0) {
        $newType = preg_replace('/\)\s*$/', ",'paid')", $info['COLUMN_TYPE']);
        $null = ($info['IS_NULLABLE'] === 'YES') ? 'NULL' : 'NOT NULL';
        $def  = ($info['COLUMN_DEFAULT'] !== null) ? "DEFAULT " . $pdo->quote($info['COLUMN_DEFAULT']) : '';
        $pdo->exec("ALTER TABLE `$table` MODIFY `status` $newType $null $def");
        echo "  `$table`: added 'paid' to the status enum.\n";
    } else {
        echo "  `$table`: status enum already has 'paid'.\n";
    }

    // 2. Add paid_at / paid_by.
    foreach (['paid_at' => 'DATETIME NULL', 'paid_by' => 'INT NULL'] as $col => $def) {
        $colExists->execute([$table, $col]);
        if ((int) $colExists->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            echo "  `$table`: added `$col`.\n";
        }
    }
}
