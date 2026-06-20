<?php
/**
 * database/generate_schema.php
 * ----------------------------
 * Regenerates database/schema_sync.sql from the CURRENT database — a full set of
 * `CREATE TABLE IF NOT EXISTS` statements for every base table. Run this on the
 * dev machine whenever you add new tables, then commit the updated schema_sync.sql
 * so production can self-create them on the next deploy.
 *
 *   php database/generate_schema.php
 */

require_once __DIR__ . '/../includes/config.php';

$tables = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

$out = "-- Auto-generated full base-table schema (idempotent). Creates any tables missing on the target DB.\n"
     . "-- Regenerate with: php database/generate_schema.php\n"
     . "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $t) {
    $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
    $ddl = $row['Create Table'] ?? '';
    if ($ddl === '') continue;
    $ddl = preg_replace('/^CREATE TABLE `/', 'CREATE TABLE IF NOT EXISTS `', $ddl);
    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
    $out .= $ddl . ";\n\n";
}
$out .= "SET FOREIGN_KEY_CHECKS=1;\n";

file_put_contents(__DIR__ . '/schema_sync.sql', $out);
echo "Wrote database/schema_sync.sql — " . count($tables) . " tables, " . round(strlen($out) / 1024) . " KB\n";
