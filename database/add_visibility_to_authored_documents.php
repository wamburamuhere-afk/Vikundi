<?php
/**
 * database/add_visibility_to_authored_documents.php
 * -------------------------------------------------
 * Per-author visibility for the Document Writer.
 *
 *   shared  (default) — every leadership user may read it. This is the existing
 *                       behaviour, so nothing changes for documents already written.
 *   private           — only the author, an admin, and anyone assigned to sign it.
 *
 * Idempotent and safe to re-run.
 *
 * Run manually:  php database/add_visibility_to_authored_documents.php
 */

require_once __DIR__ . '/../includes/config.php';

$exists = $pdo->query("SHOW TABLES LIKE 'authored_documents'")->fetchColumn();
if (!$exists) {
    echo "  authored_documents not present yet; skipped (create_authored_documents_table runs first).\n";
    return;
}

$col = $pdo->query("SHOW COLUMNS FROM authored_documents LIKE 'visibility'")->fetch(PDO::FETCH_ASSOC);
if ($col) {
    echo "authored_documents.visibility already present.\n";
    return;
}

$pdo->exec("
    ALTER TABLE authored_documents
      ADD COLUMN `visibility` ENUM('shared','private') NOT NULL DEFAULT 'shared' AFTER `status`,
      ADD KEY `idx_authored_visibility` (`visibility`)
");
echo "authored_documents.visibility added (default 'shared' — existing documents unchanged).\n";
