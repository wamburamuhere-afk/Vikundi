<?php
/**
 * database/check_schema_drift.php
 * -------------------------------
 * Schema-drift guard (audit H2). Scans every `INSERT INTO <table> (<cols>)` in
 * the codebase and reports any column the code writes that does NOT exist in the
 * live database — the class of bug that produced repeated "Unknown column"
 * failures in production.
 *
 * Run:  php database/check_schema_drift.php
 *
 * It is informational (exit 0) and prints a grouped report. Tables for unused
 * modules can be ignored via $IGNORE_TABLES below so the signal stays on the
 * VICOBA core. The column parser is a pure function so it can be unit-tested
 * without a database (see tests/Unit/SchemaDriftCheckerTest.php).
 */

/**
 * Pure parser: extract [table, column] pairs from the INSERT statements in a
 * blob of PHP/SQL source. Dynamic column lists (containing $ or ?) are skipped.
 *
 * @return array<int, array{0:string,1:string}>
 */
function vikundi_extract_insert_columns(string $code): array
{
    $out = [];
    if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^;()]*?)\)\s*(?:VALUES|SELECT)/is', $code, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $table   = strtolower($mm[1]);
            $collist = $mm[2];
            if (strpos($collist, '$') !== false || strpos($collist, '?') !== false) {
                continue; // dynamic / not a literal column list
            }
            foreach (preg_split('/\s*,\s*/', trim($collist)) as $c) {
                $c = strtolower(trim($c, " \t\n\r`"));
                if ($c !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $c)) {
                    $out[] = [$table, $c];
                }
            }
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// CLI entry point (skipped when this file is required for testing).
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require __DIR__ . '/../includes/config.php'; // $pdo

    // Unused / non-VICOBA modules — drift here doesn't affect the group.
    $IGNORE_TABLES = [
        'brands', 'invoice_items', 'purchase_returns', 'purchase_return_items',
        'warehouses', 'locations', 'deleted_expenses',
    ];

    $tableCols = static function (string $t) use ($pdo): ?array {
        static $cache = [];
        if (array_key_exists($t, $cache)) return $cache[$t];
        $s = $pdo->prepare("SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $s->execute([$t]);
        $c = $s->fetchAll(PDO::FETCH_COLUMN);
        return $cache[$t] = ($c ?: null);
    };

    $root  = dirname(__DIR__);
    $rii   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $drift = [];
    foreach ($rii as $f) {
        $p = $f->getPathname();
        if (!str_ends_with($p, '.php')) continue;
        if (strpos($p, '/vendor/') !== false || strpos($p, '/tests/') !== false) continue;
        foreach (vikundi_extract_insert_columns(file_get_contents($p)) as [$table, $col]) {
            if (in_array($table, $IGNORE_TABLES, true)) continue;
            $cols = $tableCols($table);
            if ($cols === null) continue;            // table absent locally
            if (!in_array($col, $cols, true)) {
                $drift["$table.$col"][basename($p)] = true;
            }
        }
    }

    if (!$drift) {
        echo "Schema drift check: OK — no INSERT-column drift on checked tables.\n";
        exit(0);
    }
    echo "Schema drift check: columns written by code but missing in the DB:\n";
    ksort($drift);
    foreach ($drift as $k => $files) {
        echo "  $k   <- " . implode(', ', array_keys($files)) . "\n";
    }
    echo "\n(" . count($drift) . " item(s). Ignored modules: " . implode(', ', $IGNORE_TABLES) . ")\n";
    exit(0);
}
