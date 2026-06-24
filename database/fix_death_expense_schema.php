<?php
/**
 * database/fix_death_expense_schema.php
 * -------------------------------------
 * Idempotent fixes for death-expense workflow schema drift.
 *
 * The death-expense code stores deceased_type values 'mwanachama','spouse',
 * 'child','parent' and, on a member's death approval, sets
 * customers/users.status = 'dormant' and customers.is_active = 0. Older schema
 * dumps were missing 'parent'/'mwanachama' from the deceased_type enum (causing
 * "Data truncated for column 'deceased_type'"), missing 'dormant' from the
 * status enums, and missing the customers.is_active column.
 *
 * Each step inspects the current schema and only changes what is missing — it is
 * non-destructive (widening only) and safe to run on every deploy.
 *
 * Included by database/migrate.php, which provides the shared $pdo.
 */

$db = $pdo->query('SELECT DATABASE()')->fetchColumn();

/** Return [COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT] for a column, or null if absent. */
$meta = function (string $table, string $col) use ($pdo, $db): ?array {
    $s = $pdo->prepare(
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $s->execute([$db, $table, $col]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
};

/** Rebuild the "NULL/NOT NULL [DEFAULT ...]" tail of a column so MODIFY preserves it. */
$tail = function (array $m) use ($pdo): string {
    $null = ($m['IS_NULLABLE'] === 'YES') ? 'NULL' : 'NOT NULL';
    if ($m['COLUMN_DEFAULT'] !== null) {
        return "$null DEFAULT " . $pdo->quote($m['COLUMN_DEFAULT']);
    }
    return ($m['IS_NULLABLE'] === 'YES') ? "$null DEFAULT NULL" : $null;
};

$changes = 0;

// 1. death_expenses.deceased_type -> VARCHAR(20) so 'mwanachama' and 'parent' fit.
if ($m = $meta('death_expenses', 'deceased_type')) {
    if (stripos($m['COLUMN_TYPE'], 'varchar') === false) {
        $pdo->exec("ALTER TABLE `death_expenses` MODIFY `deceased_type` VARCHAR(20) " . $tail($m));
        echo "  death_expenses.deceased_type -> VARCHAR(20)\n";
        $changes++;
    } else {
        echo "  death_expenses.deceased_type already VARCHAR — ok\n";
    }
}

// 2 & 3. Add 'dormant' to customers.status and users.status (preserving existing values).
foreach (['customers', 'users'] as $tbl) {
    if ($m = $meta($tbl, 'status')) {
        $isEnum = stripos($m['COLUMN_TYPE'], 'enum(') === 0;
        if ($isEnum && stripos($m['COLUMN_TYPE'], "'dormant'") === false) {
            $newType = rtrim($m['COLUMN_TYPE'], ')') . ",'dormant')";
            $pdo->exec("ALTER TABLE `$tbl` MODIFY `status` $newType " . $tail($m));
            echo "  $tbl.status += 'dormant'\n";
            $changes++;
        } else {
            echo "  $tbl.status already supports 'dormant' (or not an enum) — ok\n";
        }
    }
}

// 4. customers.is_active must exist (written on a member's death approval).
if (!$meta('customers', 'is_active')) {
    $pdo->exec("ALTER TABLE `customers` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
    echo "  customers.is_active added\n";
    $changes++;
} else {
    echo "  customers.is_active already exists — ok\n";
}

echo $changes ? "  ($changes change(s) applied)\n" : "  (already in sync)\n";
