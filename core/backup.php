<?php
/**
 * core/backup.php
 * ---------------
 * Canonical database-backup helpers for Vikundi, shared by:
 *   - app/constant/settings/backup_restore.php   (on-load auto backup + table)
 *   - api/backup_actions.php                      (create / pre-restore safety)
 *
 * Pure helpers, no output. Safe to require multiple times (function_exists
 * guards). Produces a portable SQL dump: schema + data for base tables, and
 * CREATE VIEW for views (dumped after the tables, never as tables with INSERTs).
 *
 * Ported from the BMS implementation. Uses PDO only (no shell-out to
 * mysqldump), so it works even on hosts where exec() is disabled — which is
 * exactly the failure mode of the older api/create_backup.php.
 */

if (!function_exists('vikundi_write_dump')) {

    /**
     * Write a full SQL dump (schema + data for base tables; CREATE VIEW for
     * views) to $filepath. Streams row-by-row to keep memory low.
     *
     * @throws Exception on any failure (caller removes the partial file).
     */
    function vikundi_write_dump(PDO $pdo, string $filepath): void {
        @set_time_limit(0);

        $handle = fopen($filepath, 'w');
        if (!$handle) {
            throw new Exception("Cannot open file for writing: $filepath");
        }

        try {
            // Split base tables from views. SHOW FULL TABLES exposes Table_type
            // ('BASE TABLE' | 'VIEW') so views are handled separately.
            $baseTables = [];
            $views      = [];
            $stmt = $pdo->query("SHOW FULL TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                // [0] = name, [1] = Table_type
                if (isset($row[1]) && strtoupper($row[1]) === 'VIEW') {
                    $views[] = $row[0];
                } else {
                    $baseTables[] = $row[0];
                }
            }

            fwrite($handle, "-- Vikundi Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n");

            // ── Base tables: structure + data ──────────────────────────────
            foreach ($baseTables as $table) {
                $tq   = "`$table`";
                $create = $pdo->query("SHOW CREATE TABLE $tq")->fetch(PDO::FETCH_NUM);
                fwrite($handle, "\nDROP TABLE IF EXISTS $tq;\n");
                fwrite($handle, $create[1] . ";\n\n");

                $rows = $pdo->query("SELECT * FROM $tq");
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $values = array_map(
                        fn($v) => is_null($v) ? 'NULL' : $pdo->quote($v),
                        $row
                    );
                    fwrite($handle, "INSERT INTO $tq VALUES(" . implode(',', $values) . ");\n");
                }
                fwrite($handle, "\n");
            }

            // ── Views: CREATE VIEW only (no data), after the tables exist ───
            foreach ($views as $view) {
                $vq = "`$view`";
                try {
                    $cv = $pdo->query("SHOW CREATE VIEW $vq")->fetch(PDO::FETCH_ASSOC);
                    // SHOW CREATE VIEW columns: View, Create View, ...
                    $createView = $cv['Create View'] ?? ($cv['Create view'] ?? null);
                    if ($createView) {
                        fwrite($handle, "\nDROP VIEW IF EXISTS $vq;\n");
                        fwrite($handle, $createView . ";\n\n");
                    }
                } catch (Throwable $e) {
                    // A view referencing a missing/renamed table — skip it
                    // rather than abort the whole backup.
                    fwrite($handle, "\n-- (skipped view $vq: " . str_replace(["\n", "\r"], ' ', $e->getMessage()) . ")\n");
                }
            }

            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);
        } catch (Throwable $e) {
            if (is_resource($handle)) fclose($handle);
            throw $e instanceof Exception ? $e : new Exception($e->getMessage());
        }
    }

    /**
     * Delete auto/pre-restore backups older than $days days (by file mtime).
     * Manual ("backup_v_*") and uploaded ("uploaded_*") files are NEVER
     * touched — only "auto_backup_*" and "pre_restore_*" are auto-pruned.
     *
     * @return string[]  filenames deleted
     */
    function vikundi_prune_backups(string $dir, int $days = 7): array {
        $dir = rtrim($dir, '/\\') . '/';
        $cutoff  = time() - ($days * 86400);
        $deleted = [];

        foreach (['auto_backup_*.sql', 'pre_restore_*.sql'] as $pattern) {
            foreach ((glob($dir . $pattern) ?: []) as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    if (@unlink($file)) $deleted[] = basename($file);
                }
            }
        }
        return $deleted;
    }

    /**
     * Current database size in MB (data + indexes), via information_schema.
     * Derives the schema name from the live connection (SELECT DATABASE()) so
     * it does not depend on a DB_NAME constant being defined.
     *
     * @return float  size in MB (0 on any error)
     */
    function vikundi_db_size_mb(PDO $pdo): float {
        try {
            $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
            if (!$db) return 0.0;
            $stmt = $pdo->prepare(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                 FROM information_schema.TABLES WHERE table_schema = ?"
            );
            $stmt->execute([$db]);
            return (float) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}
