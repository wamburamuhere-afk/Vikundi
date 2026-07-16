<?php
/**
 * database/create_authored_document_templates_table.php
 * -----------------------------------------------------
 * Reusable starting points for the Document Writer: a template holds a name, a
 * document type, a rich-text body and the letterhead preference. Writing a new
 * document can start from one instead of a blank page.
 *
 * Deliberately named `authored_document_templates` — the legacy BMS module has an
 * unrelated `document_templates` page/table which this must not collide with.
 *
 * Idempotent and safe to re-run.
 *
 * Run manually:  php database/create_authored_document_templates_table.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `authored_document_templates` (
      `id` int NOT NULL AUTO_INCREMENT,
      `name` varchar(150) NOT NULL,
      `doc_type` enum('letter','contract','notice','other') NOT NULL DEFAULT 'letter',
      `body_html` longtext,
      `use_letterhead` tinyint(1) NOT NULL DEFAULT 1,
      `created_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_tpl_type` (`doc_type`),
      KEY `idx_tpl_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

echo "authored_document_templates table ready.\n";
