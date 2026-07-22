<?php
/**
 * database/repair_mkoba_scientific_trans_ids.php
 * ----------------------------------------------
 * Excel mangles long M-Koba TRANS_IDs into scientific notation ("3.83E+15") when
 * the statement CSV is opened/saved in a spreadsheet. The true digits are lost in
 * the rounding, so we recover a real reference by falling back to the RECEIPT —
 * unique per transaction, and for most M-Koba rows TRANS_ID == RECEIPT anyway.
 *
 * This repairs rows already stored that way, in both the ledger (`contributions`)
 * and the reconciliation mirror (`mkoba_statement_rows`). New imports are repaired
 * at parse time (includes/transaction_import.php::mkoba_repair_trans_id).
 *
 * Idempotent — only touches a trans id that still looks like scientific notation
 * and has a receipt to fall back to. Registered in database/migrate.php.
 */

require_once __DIR__ . '/../includes/config.php';

$sci = '^[0-9]+(\\.[0-9]+)?[eE][+-]?[0-9]+$';

$targets = [
    // table => [trans-id column, receipt column]
    'contributions'        => ['mkoba_trans_id', 'mkoba_receipt'],
    'mkoba_statement_rows' => ['trans_id', 'receipt'],
];

$tableExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");

foreach ($targets as $table => [$tidCol, $recCol]) {
    $tableExists->execute([$table]);
    if ((int) $tableExists->fetchColumn() === 0) {
        echo "  `$table` not present here — skipped.\n";
        continue;
    }
    $stmt = $pdo->prepare("UPDATE `$table`
                              SET `$tidCol` = `$recCol`
                            WHERE `$tidCol` REGEXP :sci
                              AND `$recCol` IS NOT NULL AND `$recCol` <> ''");
    $stmt->execute(['sci' => $sci]);
    echo "  `$table`: repaired " . $stmt->rowCount() . " Excel-mangled trans id(s).\n";
}
