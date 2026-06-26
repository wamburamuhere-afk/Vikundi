<?php
/**
 * database/add_guarantor_detail_columns.php
 * -----------------------------------------
 * Registration PR-C: richer guarantor details on the member record.
 *
 * Adds an optional link to an existing member (the guarantor is often already a
 * member) plus the same six-field location used for the member's residence:
 *   guarantor_member_id
 *   guarantor_country / _state / _district / _ward / _street / _house_number
 *
 * The legacy columns (guarantor_name, guarantor_phone, guarantor_rel,
 * guarantor_location) are kept and still populated, so existing records/reports
 * are unaffected.
 *
 * Idempotent and safe to re-run (checks information_schema first). Mirrors the
 * existing nok_* / parent columns. Registered in database/migrate.php, which the
 * deploy workflow runs automatically.
 *
 * Run manually:  php database/add_guarantor_detail_columns.php
 */

require_once __DIR__ . '/../includes/config.php';

$map = ['customers' => [
    'guarantor_member_id'    => 'INT NULL',
    'guarantor_country'      => 'VARCHAR(100) NULL',
    'guarantor_state'        => 'VARCHAR(100) NULL',
    'guarantor_district'     => 'VARCHAR(100) NULL',
    'guarantor_ward'         => 'VARCHAR(100) NULL',
    'guarantor_street'       => 'VARCHAR(150) NULL',
    'guarantor_house_number' => 'VARCHAR(50) NULL',
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

echo "Guarantor detail column sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " column(s): " . implode(', ', $added) . "\n")
    : "  No missing columns — schema already in sync.\n";
if ($skippedTables) echo "  Skipped (table not present here): " . implode(', ', $skippedTables) . "\n";
