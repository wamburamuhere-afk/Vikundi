<?php
/**
 * database/add_late_payment_rules.php
 * -----------------------------------
 * Late-payment rules (includes/late_rules.php):
 *
 *   fines.late_period CHAR(7) NULL       — the month ('Y-m') a late fine is for (NULL = other fine)
 *   UNIQUE fines(customer_id, late_period) — at most one late fine per member per month
 *   customers.late_rule_dormant TINYINT  — 1 when the rule (not a leader) made the member Dormant,
 *                                          so only those are reactivated automatically
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_late_payment_rules.php
 */

require_once __DIR__ . '/../includes/config.php';

$map = [
    'fines'     => ['late_period' => 'CHAR(7) NULL DEFAULT NULL'],
    'customers' => ['late_rule_dormant' => 'TINYINT(1) NOT NULL DEFAULT 0'],
];

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

// One late fine per member per month, enforced by the database (the rule uses INSERT IGNORE).
if (!in_array('fines', $skippedTables, true)) {
    $idx = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fines' AND INDEX_NAME = 'uniq_late_fine'")->fetchColumn();
    if ((int) $idx === 0) {
        $pdo->exec("ALTER TABLE `fines` ADD UNIQUE KEY `uniq_late_fine` (`customer_id`, `late_period`)");
        $added[] = 'fines.uniq_late_fine';
    }
}

echo "Late-payment rules schema sync complete.\n";
echo $added
    ? ("  Added: " . implode(', ', $added) . "\n")
    : "  No changes — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
