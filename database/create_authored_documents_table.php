<?php
/**
 * database/create_authored_documents_table.php
 * --------------------------------------------
 * Document authoring: in-system letters / contracts / notices written with a
 * rich-text editor, optionally on the group letterhead, and signed via the
 * existing e-signature system (workflow_signatures, entity type
 * 'authored_document').
 *
 * Creates:
 *   authored_documents — the document record (title, type, HTML body, letterhead
 *                        flag, status, author)
 * and registers the `manage_documents` permission page-key.
 *
 * Idempotent and safe to re-run.
 *
 * Run manually:  php database/create_authored_documents_table.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `authored_documents` (
      `id` int NOT NULL AUTO_INCREMENT,
      `title` varchar(255) NOT NULL,
      `doc_type` enum('letter','contract','notice','other') NOT NULL DEFAULT 'letter',
      `body_html` longtext,
      `use_letterhead` tinyint(1) NOT NULL DEFAULT 1,
      `status` enum('draft','final') NOT NULL DEFAULT 'draft',
      `created_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_authored_created_by` (`created_by`),
      KEY `idx_authored_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Register the permission page-key (idempotent — page_key is UNIQUE).
$check = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$check->execute(['manage_documents']);
if (!$check->fetchColumn()) {
    $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?,?,?,?,?)")
        ->execute(['', 'manage_documents', 'Document Writer', 'Write, print and sign letters, contracts and notices', 'Documents']);
    echo "authored_documents table ready. Added 'manage_documents' permission.\n";
} else {
    echo "authored_documents table ready. 'manage_documents' permission already present.\n";
}
