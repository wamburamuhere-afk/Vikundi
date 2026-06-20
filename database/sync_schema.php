<?php
/**
 * database/sync_schema.php
 * ------------------------
 * Creates any BASE TABLES that exist in the codebase's schema but are missing on
 * the target database (the cause of "Base table or view not found" fatals like
 * `workflow_signatures doesn't exist`). Reads database/schema_sync.sql, which is a
 * full set of `CREATE TABLE IF NOT EXISTS` statements — so existing tables and
 * their data are never touched; only missing tables are created (empty).
 *
 * Idempotent and safe to run on every deploy.
 *
 * Regenerate schema_sync.sql from the current DB with:
 *   php database/generate_schema.php
 */

require_once __DIR__ . '/../includes/config.php';

$file = __DIR__ . '/schema_sync.sql';
if (!is_file($file)) {
    echo "  schema_sync.sql not found — skipped.\n";
    return;
}

$countSql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'";
$before = (int) $pdo->query($countSql)->fetchColumn();

try { $pdo->exec("SET FOREIGN_KEY_CHECKS=0"); } catch (Throwable $e) {}

$sql  = file_get_contents($file);
$warn = 0;
foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || stripos($stmt, 'CREATE TABLE') === false) continue; // only run CREATE TABLE statements
    try {
        $pdo->exec($stmt);
    } catch (Throwable $e) {
        $warn++;
        echo "  warn: " . substr($e->getMessage(), 0, 140) . "\n";
    }
}

try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $e) {}

$after = (int) $pdo->query($countSql)->fetchColumn();
$created = $after - $before;

echo "Schema sync complete.\n";
echo $created > 0 ? "  Created $created missing table(s) (was $before, now $after).\n"
                  : "  No missing tables — all present ($after).\n";
if ($warn) echo "  ($warn statement warning(s) ignored.)\n";
